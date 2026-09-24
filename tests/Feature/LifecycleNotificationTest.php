<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Notifications\AccessSuspendedNotification;
use Modules\Billing\Notifications\GraceStartedNotification;
use Modules\Billing\Notifications\TrialEndingNotification;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Settings\BillingSettings;
use Modules\Billing\Tests\Support\StripeWebhook;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * What the customer hears about their subscription's lifecycle. Each mail is
 * tied to a transition, so repeating the event that caused it says nothing new.
 */
class LifecycleNotificationTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    private User $user;

    private Subscription $subscription;

    /** @var list<WebhookData> */
    private array $deliveries = [];

    private int $delivered = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->gateway = $this->createMock(StripeGateway::class);
        $this->gateway->method('verifyAndParseWebhook')->willReturnCallback(function (): WebhookData {
            return array_shift($this->deliveries);
        });

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->billing = app(BillingService::class);
        app(BillingSettings::class)->fill(['grace_period_days' => 3])->save();

        $this->user = User::factory()->create();
        $this->subscription = Subscription::factory()->create([
            'customer_id' => Customer::factory()->for($this->user, 'owner')->create()->id,
            'provider' => 'stripe',
            'provider_subscription_id' => 'sub_mail',
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function deliver(WebhookEventType $type, array $payload): void
    {
        $this->deliveries[] = StripeWebhook::make(
            type: $type,
            provider: 'stripe',
            providerEventId: 'evt_'.++$this->delivered,
            payload: ['id' => 'sub_mail', ...$payload],
        );

        $this->billing->handleWebhook('stripe', request());
    }

    public function test_falling_behind_asks_the_customer_to_fix_their_card(): void
    {
        $this->deliver(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);

        Notification::assertSentTo($this->user, GraceStartedNotification::class);
    }

    /** The window did not move, so there is nothing new to say. */
    public function test_a_second_failure_does_not_write_again(): void
    {
        $this->deliver(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);
        $this->deliver(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);

        Notification::assertSentToTimes($this->user, GraceStartedNotification::class, 1);
    }

    public function test_suspension_is_announced_once(): void
    {
        $this->deliver(WebhookEventType::SubscriptionUpdated, ['status' => 'unpaid']);
        $this->deliver(WebhookEventType::SubscriptionUpdated, ['status' => 'unpaid']);

        Notification::assertSentToTimes($this->user, AccessSuspendedNotification::class, 1);
    }

    public function test_the_sweeper_announces_the_suspension_it_makes(): void
    {
        $this->subscription->update(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->subHour()]);

        $this->artisan('billing:end-grace-periods');
        $this->artisan('billing:end-grace-periods');

        Notification::assertSentToTimes($this->user, AccessSuspendedNotification::class, 1);
    }

    /** With no window to warn about, the only news is the suspension itself. */
    public function test_a_grace_period_of_zero_days_only_announces_the_suspension(): void
    {
        app(BillingSettings::class)->fill(['grace_period_days' => 0])->save();

        $this->deliver(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);

        Notification::assertNotSentTo($this->user, GraceStartedNotification::class);
        Notification::assertSentTo($this->user, AccessSuspendedNotification::class);
    }

    public function test_a_trial_about_to_end_is_announced(): void
    {
        $this->deliver(WebhookEventType::SubscriptionTrialWillEnd, [
            'status' => 'trialing',
            'trial_end' => now()->addDays(3)->timestamp,
        ]);

        Notification::assertSentTo($this->user, TrialEndingNotification::class);
    }

    public function test_recovering_says_nothing_about_grace(): void
    {
        $this->deliver(WebhookEventType::SubscriptionUpdated, ['status' => 'past_due']);
        $this->deliver(WebhookEventType::SubscriptionUpdated, ['status' => 'active']);

        Notification::assertSentToTimes($this->user, GraceStartedNotification::class, 1);
        Notification::assertNotSentTo($this->user, AccessSuspendedNotification::class);
    }

    /** A retired plan is still the one the customer holds. */
    public function test_the_emails_name_a_retired_plan(): void
    {
        $plan = $this->subscription->price->product;
        $plan->delete();
        $subscription = $this->subscription->fresh();

        foreach ([GraceStartedNotification::class, AccessSuspendedNotification::class, TrialEndingNotification::class] as $notification) {
            $mail = (new $notification($subscription))->toMail($this->user);

            $this->assertStringContainsString($plan->name, implode(' ', $mail->introLines), $notification);
        }
    }

    /** With nothing to charge the provider cancels, so "nothing to do" would be wrong. */
    public function test_a_trial_without_payment_details_is_told_to_add_them(): void
    {
        $this->subscription->update(['payment_method_id' => null]);

        $mail = (new TrialEndingNotification($this->subscription->fresh()))->toMail($this->user);

        $this->assertSame(route('billing.portal'), $mail->actionUrl);
    }

    public function test_a_trial_with_payment_details_just_continues(): void
    {
        $method = PaymentMethod::factory()->create(['customer_id' => $this->subscription->customer_id]);
        $this->subscription->update(['payment_method_id' => $method->id]);

        $mail = (new TrialEndingNotification($this->subscription->fresh()))->toMail($this->user);

        $this->assertSame(route('settings.billing'), $mail->actionUrl);
    }
}
