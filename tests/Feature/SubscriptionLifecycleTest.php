<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\PlanAction;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\Gateways\StripeEventMapper;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Services\PlanActions;
use Modules\Billing\Settings\BillingSettings;
use Modules\Billing\Tests\Support\StripeWebhook;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * Whole stories, told over days: what a customer can use at each step, as the
 * provider's events arrive and the sweeper runs. The rules behind each step
 * have their own tests; these catch the seams between them.
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    private User $user;

    private Customer $customer;

    private Product $plan;

    private Subscription $subscription;

    /** @var list<WebhookData> */
    private array $deliveries = [];

    private int $delivered = 0;

    /** What the provider answers when the app asks it about the subscription. */
    private string $providerSays = 'active';

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(StripeGateway::class);
        $this->gateway->method('verifyAndParseWebhook')->willReturnCallback(fn (): WebhookData => array_shift($this->deliveries));
        $this->gateway->method('retrieveSubscription')->willReturnCallback(fn (): SubscriptionStateData => StripeEventMapper::subscription(['id' => 'sub_story', 'status' => $this->providerSays]));

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->billing = app(BillingService::class);
        app(BillingSettings::class)->fill(['grace_period_days' => 3])->save();

        $this->user = $this->createUser();
        $this->customer = Customer::factory()->create(['user_id' => $this->user->id]);
        $this->plan = Product::factory()->create([
            'trial_days' => 14,
            'entitlements' => ['features' => ['exports' => true]],
        ]);
        $this->subscription = Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->create(['product_id' => $this->plan->id])->id,
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_story',
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function provider(WebhookEventType $type, array $payload = []): void
    {
        $this->deliveries[] = StripeWebhook::make(
            type: $type,
            provider: 'stripe',
            providerEventId: 'evt_'.++$this->delivered,
            payload: ['id' => 'sub_story', ...$payload],
        );

        $this->billing->handleWebhook('stripe', request());
    }

    private function invoicePaid(): void
    {
        $this->provider(WebhookEventType::PaymentSucceeded, [
            'id' => 'in_'.$this->delivered,
            'payment_intent' => 'pi_'.$this->delivered,
            'subscription' => 'sub_story',
            'customer' => $this->customer->provider_customer_id,
            'currency' => 'eur',
            'amount_paid' => 2900,
        ]);
    }

    private function canUseThePlan(): bool
    {
        return $this->user->fresh()->canUseFeature('exports');
    }

    public function test_a_trial_that_converts_keeps_access_throughout(): void
    {
        $this->provider(WebhookEventType::SubscriptionUpdated, [
            'status' => 'trialing',
            'trial_start' => now()->timestamp,
            'trial_end' => now()->addDays(14)->timestamp,
        ]);
        $this->assertTrue($this->canUseThePlan());

        $this->travel(15)->days();
        $this->provider(WebhookEventType::SubscriptionUpdated, ['status' => 'active']);

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->trial_ends_at->isPast());
        $this->assertTrue($this->canUseThePlan());
    }

    /** No card at the end: the provider cancels. No grace, and no second trial. */
    public function test_a_trial_that_ends_without_a_card_is_over(): void
    {
        $this->provider(WebhookEventType::SubscriptionUpdated, [
            'status' => 'trialing',
            'trial_start' => now()->timestamp,
            'trial_end' => now()->addDays(14)->timestamp,
        ]);

        $this->travel(14)->days();
        $this->provider(WebhookEventType::SubscriptionDeleted, ['status' => 'canceled']);

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertFalse($this->canUseThePlan());

        $price = $this->plan->prices()->first();
        $this->assertSame(PlanAction::Buy, app(PlanActions::class)->for($this->user->fresh(), collect([$this->plan->fresh(['prices'])]))['priceActions'][$price->id]);
    }

    public function test_falling_behind_then_paying_after_suspension(): void
    {
        $this->provider(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);

        $this->travel(2)->days();
        $this->assertTrue($this->canUseThePlan(), 'Inside the grace window');

        $this->travel(2)->days();
        $this->assertFalse($this->canUseThePlan(), 'Past the deadline, before the sweeper has run');

        $this->artisan('billing:end-grace-periods');
        $this->assertSame(SubscriptionStatus::Suspended, $this->subscription->fresh()->status);

        $this->travel(5)->days();
        $this->invoicePaid();

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertTrue($this->canUseThePlan());
    }

    public function test_cancelling_during_grace_ends_the_episode(): void
    {
        $this->provider(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);

        $this->travel(1)->days();
        $this->provider(WebhookEventType::SubscriptionDeleted, ['status' => 'canceled']);

        $subscription = $this->subscription->fresh();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertFalse($this->canUseThePlan());
    }

    /** A cancellation landing while a paid invoice asks the provider must win. */
    public function test_a_cancellation_during_a_provider_read_is_not_overwritten(): void
    {
        $this->provider(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);

        $this->gateway = $this->createMock(StripeGateway::class);
        $this->providerSays = 'active';
        $cancelled = false;
        $gateway = $this->gateway;
        $gateway->method('verifyAndParseWebhook')->willReturnCallback(fn (): WebhookData => array_shift($this->deliveries));
        $gateway->method('retrieveSubscription')->willReturnCallback(function () use (&$cancelled): SubscriptionStateData {
            // The customer cancels while the app waits on the provider.
            if (! $cancelled) {
                $cancelled = true;
                $this->provider(WebhookEventType::SubscriptionDeleted, ['status' => 'canceled']);
            }

            return StripeEventMapper::subscription(['id' => 'sub_story', 'status' => 'active']);
        });
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
        app()->forgetInstance(BillingService::class);
        $this->billing = app(BillingService::class);

        $this->invoicePaid();

        $this->assertSame(SubscriptionStatus::Cancelled, $this->subscription->fresh()->status);
    }

    /** An admin lengthening the grace period does not move a deadline already set. */
    public function test_changing_the_grace_setting_leaves_a_running_window_alone(): void
    {
        $this->provider(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);
        $deadline = $this->subscription->fresh()->grace_ends_at;

        app(BillingSettings::class)->fill(['grace_period_days' => 10])->save();
        $this->travel(1)->days();
        $this->provider(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);

        $this->assertTrue($deadline->equalTo($this->subscription->fresh()->grace_ends_at));
    }
}
