<?php

namespace Modules\Billing\Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\MockObject\MockObject;
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

    private function subscribe(): Subscription
    {
        return Subscription::factory()->create(['customer_id' => $this->customer->id]);
    }

    private function buyLifetime(): Payment
    {
        return Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->oneTime()->create()->id,
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

    public function test_a_subscriber_cannot_buy_a_second_subscription(): void
    {
        $this->subscribe();

        $this->expectException(ValidationException::class);

        $this->billing->assertCanBuy($this->customer, Price::factory()->create());
    }

    public function test_a_lifetime_owner_cannot_buy_a_subscription(): void
    {
        $this->buyLifetime();

        $this->expectException(ValidationException::class);

        $this->billing->assertCanBuy($this->customer, Price::factory()->create());
    }

    public function test_a_lifetime_owner_cannot_buy_lifetime_again(): void
    {
        $this->buyLifetime();

        $this->expectException(ValidationException::class);

        $this->billing->assertCanBuy($this->customer, Price::factory()->oneTime()->create());
    }

    public function test_a_subscriber_can_upgrade_to_lifetime(): void
    {
        $this->subscribe();

        $this->billing->assertCanBuy($this->customer, Price::factory()->oneTime()->create());

        $this->addToAssertionCount(1);
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

    public function test_buying_lifetime_grants_access_and_ends_the_subscription_at_period_end(): void
    {
        $subscription = $this->subscribe();
        $session = CheckoutSession::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->oneTime()->create()->id,
            'provider_session_id' => 'cs_lifetime',
        ]);

        $this->gateway->expects($this->once())
            ->method('cancelSubscription')
            ->willReturn($subscription->current_period_ends_at);

        $this->webhook(WebhookEventType::CheckoutCompleted, [
            'id' => 'cs_lifetime',
            'payment_intent' => 'pi_lifetime',
            'currency' => 'eur',
            'amount_total' => 29900,
        ]);

        $this->assertSame(CheckoutSessionStatus::Completed, $session->fresh()->status);
        $this->assertNotNull($subscription->fresh()->cancelled_at);
        $this->assertTrue($this->user->fresh()->hasRole(Role::SUBSCRIBER));
    }

    public function test_a_full_refund_takes_lifetime_access_back(): void
    {
        $payment = $this->buyLifetime();
        $this->user->assignRole(Role::SUBSCRIBER);

        $this->webhook(WebhookEventType::PaymentRefunded, [
            'payment_intent' => 'pi_lifetime',
            'refunded' => true,
            'amount_refunded' => $payment->amount,
        ]);

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertFalse($this->user->fresh()->hasRole(Role::SUBSCRIBER));
    }

    public function test_a_partial_refund_keeps_access(): void
    {
        $payment = $this->buyLifetime();
        $this->user->assignRole(Role::SUBSCRIBER);

        $this->webhook(WebhookEventType::PaymentRefunded, [
            'payment_intent' => 'pi_lifetime',
            'refunded' => false,
            'amount_refunded' => 100,
        ]);

        $this->assertSame(PaymentStatus::Succeeded, $payment->fresh()->status);
        $this->assertSame(100, $payment->fresh()->amount_refunded);
        $this->assertTrue($this->user->fresh()->hasRole(Role::SUBSCRIBER));
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

    /** Delivered before the checkout that created the payment: retried, not dropped. */
    public function test_a_refund_that_beats_its_payment_is_retried(): void
    {
        $this->customer->update(['provider_customer_id' => 'cus_known']);

        $this->expectException(\RuntimeException::class);

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
}
