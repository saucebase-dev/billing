<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Saucebase\Core\Settings\SettingsSection;
use Tests\TestCase;

/**
 * One plan per customer: a subscription or lifetime access, never paid for
 * twice, changed at the provider, and taken back by a full refund.
 */
class PlanAccessTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(StripeGateway::class);

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->billing = app(BillingService::class);
        $this->user = $this->createUser();
        $this->customer = Customer::factory()->create(['user_id' => $this->user->id]);
    }

    private function subscribe(?Product $plan = null): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->create(['product_id' => ($plan ?? Product::factory()->create())->id])->id,
        ]);
    }

    private function lifetimePlan(?Product $replaces = null): Product
    {
        return Product::factory()->lifetime($replaces)->create(['entitlements' => ['features' => ['exports' => true]]]);
    }

    private function buyLifetime(?Product $plan = null): Payment
    {
        return Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->oneTime()->create(['product_id' => ($plan ?? $this->lifetimePlan())->id])->id,
            'provider_payment_id' => 'pi_lifetime',
        ]);
    }

    private function webhook(WebhookEventType $type, array $payload): void
    {
        $this->gateway->method('verifyAndParseWebhook')->willReturn(new WebhookData(
            type: $type,
            provider: 'stripe',
            providerEventId: 'evt_'.uniqid(),
            payload: $payload,
        ));

        $this->billing->handleWebhook('stripe', request());
    }

    public function test_checkout_refuses_what_eligibility_refuses(): void
    {
        $this->subscribe();

        $this->expectException(ValidationException::class);

        $this->billing->assertCanBuy($this->user, Price::factory()->create());
    }

    /** The server refuses it: the pricing page hiding the button is not enough. */
    public function test_checkout_refuses_a_second_subscription_before_opening_a_session(): void
    {
        $this->subscribe();

        $this->actingAs($this->user)
            ->post(route('billing.checkout.create'), ['price_id' => Price::factory()->create()->id])
            ->assertSessionHasErrors('price_id');

        $this->assertDatabaseCount('checkout_sessions', 0);
    }

    public function test_a_plan_changed_at_the_provider_moves_the_subscription(): void
    {
        $subscription = $this->subscribe();
        $newPrice = Price::factory()->yearly()->create(['provider' => 'stripe']);

        $this->webhook(WebhookEventType::SubscriptionUpdated, [
            'id' => $subscription->provider_subscription_id,
            'customer' => $this->customer->provider_customer_id,
            'status' => 'active',
            'items' => ['data' => [['price' => ['id' => $newPrice->provider_price_id]]]],
        ]);

        $this->assertSame($newPrice->id, $subscription->fresh()->price_id);
    }

    public function test_a_price_unknown_here_leaves_the_subscription_on_its_old_one(): void
    {
        $subscription = $this->subscribe();

        $this->webhook(WebhookEventType::SubscriptionUpdated, [
            'id' => $subscription->provider_subscription_id,
            'customer' => $this->customer->provider_customer_id,
            'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_not_synced']]]],
        ]);

        $this->assertSame($subscription->price_id, $subscription->fresh()->price_id);
    }

    private function completeLifetimeCheckout(Product $lifetime): CheckoutSession
    {
        $session = CheckoutSession::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->oneTime()->create(['product_id' => $lifetime->id])->id,
            'provider_session_id' => 'cs_lifetime',
        ]);

        $this->webhook(WebhookEventType::CheckoutCompleted, [
            'id' => 'cs_lifetime',
            'payment_intent' => 'pi_lifetime',
            'currency' => 'eur',
            'amount_total' => 29900,
        ]);

        return $session;
    }

    public function test_buying_lifetime_ends_the_subscription_it_replaces_at_period_end(): void
    {
        $pro = Product::factory()->create();
        $subscription = $this->subscribe($pro);

        $this->gateway->expects($this->once())
            ->method('cancelSubscription')
            ->willReturn($subscription->current_period_ends_at);

        $session = $this->completeLifetimeCheckout($this->lifetimePlan($pro));

        $this->assertSame(CheckoutSessionStatus::Completed, $session->fresh()->status);
        $this->assertNotNull($subscription->fresh()->cancelled_at);
        $this->assertTrue($this->user->fresh()->canUseFeature('exports'));
    }

    /** Lifetime covers its own plan only; a subscription to another plan keeps renewing. */
    public function test_buying_lifetime_leaves_a_subscription_it_does_not_replace(): void
    {
        $subscription = $this->subscribe();

        $this->gateway->expects($this->never())->method('cancelSubscription');

        $this->completeLifetimeCheckout($this->lifetimePlan(Product::factory()->create()));

        $this->assertNull($subscription->fresh()->cancelled_at);
    }

    public function test_a_full_refund_takes_lifetime_access_back(): void
    {
        $payment = $this->buyLifetime();

        $this->webhook(WebhookEventType::PaymentRefunded, [
            'payment_intent' => 'pi_lifetime',
            'refunded' => true,
            'amount_refunded' => $payment->amount,
        ]);

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertFalse($this->user->fresh()->canUseFeature('exports'));
    }

    public function test_a_partial_refund_keeps_access(): void
    {
        $payment = $this->buyLifetime();

        $this->webhook(WebhookEventType::PaymentRefunded, [
            'payment_intent' => 'pi_lifetime',
            'refunded' => false,
            'amount_refunded' => 100,
        ]);

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(100, $payment->fresh()->amount_refunded);
        $this->assertTrue($this->user->fresh()->canUseFeature('exports'));
    }

    /** Replaced by lifetime and running out: the app offers no way back into it. */
    public function test_a_subscription_replaced_by_lifetime_cannot_be_changed_or_resumed(): void
    {
        $pro = Product::factory()->create();
        $this->subscribe($pro)->update(['cancelled_at' => now(), 'ends_at' => now()->addWeek()]);
        $this->buyLifetime($this->lifetimePlan($pro));

        $this->actingAs($this->user)->get(route('billing.plan.change'))->assertNotFound();
        $this->actingAs($this->user)->post(route('billing.subscription.resume'))->assertSessionHasErrors();
    }

    public function test_changing_plan_needs_a_subscription(): void
    {
        $this->actingAs($this->user)->get(route('billing.plan.change'))->assertNotFound();
    }

    public function test_changing_plan_sends_the_subscriber_to_the_provider(): void
    {
        $subscription = $this->subscribe();

        $this->gateway->method('getPlanChangeUrl')
            ->with($this->callback(fn (Subscription $given) => $given->is($subscription)))
            ->willReturn('https://billing.stripe.com/p/session/test');

        $this->actingAs($this->user)
            ->get(route('billing.plan.change'))
            ->assertRedirect('https://billing.stripe.com/p/session/test');
    }

    /** Stripe refuses when its portal has plan switching turned off. */
    public function test_a_provider_that_refuses_the_plan_change_sends_the_subscriber_back(): void
    {
        $this->subscribe();

        $this->gateway->method('getPlanChangeUrl')->willThrowException(new RuntimeException('portal disabled'));

        $this->actingAs($this->user)
            ->get(route('billing.plan.change'))
            ->assertRedirect(SettingsSection::url('billing'));

        $this->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('toast.type', 'error'));
    }

    /** Delivered before the checkout that created the payment: retried, not dropped. */
    public function test_a_refund_that_beats_its_payment_is_retried(): void
    {
        $this->customer->update(['provider_customer_id' => 'cus_known']);

        $this->expectException(RuntimeException::class);

        $this->webhook(WebhookEventType::PaymentRefunded, [
            'id' => 'ch_early',
            'customer' => 'cus_known',
            'payment_intent' => 'pi_not_yet_recorded',
            'refunded' => true,
        ]);
    }

    /** A null ID must not match some other customer's payment. */
    public function test_a_refund_without_a_payment_intent_touches_nothing(): void
    {
        $unrelated = Payment::factory()->create(['provider_payment_id' => null]);

        $this->webhook(WebhookEventType::PaymentRefunded, [
            'id' => 'ch_no_intent',
            'payment_intent' => null,
            'refunded' => true,
        ]);

        $this->assertSame(PaymentStatus::Succeeded, $unrelated->fresh()->status);
    }

    /**
     * A suspended subscription is still alive at the provider, which keeps
     * retrying its invoice. Lifetime must end it, or a successful retry bills
     * the customer for a plan they already own for good.
     */
    public function test_buying_lifetime_ends_a_suspended_subscription_it_replaces(): void
    {
        $pro = Product::factory()->create();
        $subscription = $this->subscribe($pro);
        $subscription->update(['status' => SubscriptionStatus::Suspended, 'grace_ends_at' => now()->subDay()]);

        $this->gateway->expects($this->once())->method('cancelSubscription')->willReturn($subscription->current_period_ends_at);

        $this->completeLifetimeCheckout($this->lifetimePlan($pro));

        $this->assertNotNull($subscription->fresh()->cancelled_at);
    }
}
