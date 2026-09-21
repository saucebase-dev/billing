<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Data\CustomerData;
use Modules\Billing\Data\PaymentMethodData;
use Modules\Billing\Data\PaymentMethodDetails;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\PaymentMethodType;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Events\CheckoutCompleted;
use Modules\Billing\Events\InvoicePaid;
use Modules\Billing\Events\PaymentFailed;
use Modules\Billing\Events\PaymentSucceeded;
use Modules\Billing\Events\SubscriptionCancelled;
use Modules\Billing\Events\SubscriptionCreated;
use Modules\Billing\Events\SubscriptionUpdated;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\WebhookEvent;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billingService;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(StripeGateway::class);
        $this->gateway->method('resolvePaymentMethod')->willReturn(
            new PaymentMethodData(
                providerPaymentMethodId: 'pm_test_123',
                type: PaymentMethodType::Card,
                details: new PaymentMethodDetails(
                    brand: 'visa',
                    last4: '4242',
                    expMonth: 12,
                    expYear: 2030,
                ),
            ),
        );

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->billingService = app()->make(BillingService::class);
    }

    public function test_process_checkout_creates_customer(): void
    {
        $user = User::factory()->create();
        $price = Price::factory()->create();
        $session = CheckoutSession::create([
            'price_id' => $price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addHours(24),
        ]);

        $this->gateway->method('createCustomer')->willReturn('cus_test_123');
        $this->gateway->method('createCheckoutSession')->willReturn(
            new CheckoutResultData(sessionId: 'cs_guest_123', url: 'https://stripe.com/checkout', provider: 'stripe'),
        );

        $billingDetails = [
            'name' => 'Billing Name',
            'email' => 'billing@example.com',
            'phone' => '+1234567890',
            'address' => [
                'street' => '123 Main St',
                'city' => 'Springfield',
                'state' => 'IL',
                'postal_code' => '62701',
                'country' => 'US',
            ],
        ];

        $result = $this->billingService->processCheckout($session, $user, 'https://example.com/success', 'https://example.com/cancel', $billingDetails);

        $this->assertEquals('cs_guest_123', $result->sessionId);
        $this->assertEquals('https://stripe.com/checkout', $result->url);

        $this->assertDatabaseHas('customers', [
            'user_id' => $user->id,
            'provider_customer_id' => 'cus_test_123',
            'name' => 'Billing Name',
            'email' => 'billing@example.com',
            'phone' => '+1234567890',
        ]);

        $session->refresh();
        $this->assertNotNull($session->customer_id);
        $this->assertEquals('cs_guest_123', $session->provider_session_id);
    }

    public function test_checkout_session_generates_uuid_automatically(): void
    {
        $price = Price::factory()->create();
        $session = CheckoutSession::create([
            'price_id' => $price->id,
            'status' => CheckoutSessionStatus::Pending,
        ]);

        $this->assertTrue(strlen($session->uuid) === 36);
    }

    public function test_checkout_session_uses_uuid_as_route_key(): void
    {
        $session = new CheckoutSession;
        $this->assertEquals('uuid', $session->getRouteKeyName());
    }

    public function test_cancel_delegates_to_gateway(): void
    {
        $subscription = Subscription::factory()->create();

        $this->gateway->expects($this->once())
            ->method('cancelSubscription')
            ->with($subscription, false);

        $this->billingService->cancel($subscription);
    }

    public function test_cancel_immediately_delegates_to_gateway(): void
    {
        $subscription = Subscription::factory()->create();

        $this->gateway->expects($this->once())
            ->method('cancelSubscription')
            ->with($subscription, true);

        $this->billingService->cancel($subscription, immediately: true);
    }

    public function test_webhook_checkout_completed_creates_subscription(): void
    {
        Event::fake([CheckoutCompleted::class, SubscriptionCreated::class, PaymentSucceeded::class]);

        $session = CheckoutSession::factory()->create([
            'provider_session_id' => 'cs_test_789',
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::CheckoutCompleted,
            provider: 'stripe',
            providerEventId: 'evt_test_1',
            payload: [
                'id' => 'cs_test_789',
                'subscription' => 'sub_test_123',
                'currency' => 'eur',
                'amount_total' => 2900,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $session->refresh();
        $this->assertEquals(CheckoutSessionStatus::Completed, $session->status);

        $subscription = Subscription::where('provider_subscription_id', 'sub_test_123')->first();
        $this->assertNotNull($subscription);
        $this->assertEquals($session->customer_id, $subscription->customer_id);
        $this->assertEquals(SubscriptionStatus::Active, $subscription->status);
        $this->assertNotNull($subscription->payment_method_id);
        $this->assertDatabaseHas('payment_methods', [
            'provider_payment_method_id' => 'pm_test_123',
        ]);

        // Checkout now also creates the initial payment for the subscription
        $payment = Payment::where('subscription_id', $subscription->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals($session->customer_id, $payment->customer_id);
        $this->assertEquals($session->price_id, $payment->price_id);
        $this->assertEquals(2900, $payment->amount);
        $this->assertEquals(PaymentStatus::Succeeded, $payment->status);

        Event::assertDispatched(CheckoutCompleted::class);
        Event::assertDispatched(SubscriptionCreated::class);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_webhook_checkout_completed_syncs_period_dates_from_gateway(): void
    {
        Event::fake([CheckoutCompleted::class, SubscriptionCreated::class, PaymentSucceeded::class]);

        $periodStart = 1770827927;
        $periodEnd = 1773247127;

        $this->gateway->method('retrieveSubscription')->willReturn([
            'id' => 'sub_test_period',
            'items' => ['data' => [[
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]]],
        ]);

        $session = CheckoutSession::factory()->create([
            'provider_session_id' => 'cs_test_period',
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::CheckoutCompleted,
            provider: 'stripe',
            providerEventId: 'evt_test_period',
            payload: [
                'id' => 'cs_test_period',
                'subscription' => 'sub_test_period',
                'currency' => 'eur',
                'amount_total' => 2900,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription = Subscription::where('provider_subscription_id', 'sub_test_period')->first();
        $this->assertNotNull($subscription);
        $this->assertNotNull($subscription->current_period_starts_at);
        $this->assertNotNull($subscription->current_period_ends_at);
        $this->assertEquals($periodStart, $subscription->current_period_starts_at->getTimestamp());
        $this->assertEquals($periodEnd, $subscription->current_period_ends_at->getTimestamp());

        Event::assertDispatched(SubscriptionCreated::class);
    }

    public function test_webhook_checkout_completed_creates_payment_for_one_time_purchase(): void
    {
        Event::fake([CheckoutCompleted::class, PaymentSucceeded::class]);

        $session = CheckoutSession::factory()->create([
            'provider_session_id' => 'cs_test_onetime',
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::CheckoutCompleted,
            provider: 'stripe',
            providerEventId: 'evt_test_onetime',
            payload: [
                'id' => 'cs_test_onetime',
                'payment_intent' => 'pi_test_onetime',
                'currency' => 'usd',
                'amount_total' => 29900,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $session->refresh();
        $this->assertEquals(CheckoutSessionStatus::Completed, $session->status);

        $payment = Payment::where('provider_payment_id', 'pi_test_onetime')->first();
        $this->assertNotNull($payment);
        $this->assertEquals($session->customer_id, $payment->customer_id);
        $this->assertEquals(29900, $payment->amount);
        $this->assertEquals(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->payment_method_id);
        $this->assertDatabaseHas('payment_methods', [
            'provider_payment_method_id' => 'pm_test_123',
        ]);
        $this->assertDatabaseCount('subscriptions', 0);

        Event::assertDispatched(CheckoutCompleted::class);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_webhook_checkout_completed_creates_payment_for_subscription(): void
    {
        Event::fake([CheckoutCompleted::class, SubscriptionCreated::class, PaymentSucceeded::class]);

        $session = CheckoutSession::factory()->create([
            'provider_session_id' => 'cs_test_sub_pay',
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::CheckoutCompleted,
            provider: 'stripe',
            providerEventId: 'evt_test_sub_pay',
            payload: [
                'id' => 'cs_test_sub_pay',
                'subscription' => 'sub_test_sub_pay',
                'currency' => 'eur',
                'amount_total' => 2900,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription = Subscription::where('provider_subscription_id', 'sub_test_sub_pay')->first();
        $this->assertNotNull($subscription);

        $payment = Payment::where('subscription_id', $subscription->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals($session->customer_id, $payment->customer_id);
        $this->assertEquals($session->price_id, $payment->price_id);
        $this->assertEquals(2900, $payment->amount);
        $this->assertEquals(PaymentStatus::Succeeded, $payment->status);

        Event::assertDispatched(SubscriptionCreated::class);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    /**
     * The invoice can arrive before the checkout that creates its subscription.
     * Failing leaves the event without a receipt, so the provider's retry is
     * processed once the subscription exists rather than skipped as a duplicate.
     */
    public function test_an_invoice_that_arrives_before_its_subscription_is_processed_on_retry(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $customer = Customer::factory()->create(['provider_customer_id' => 'cus_test_early']);

        $webhook = new WebhookData(
            type: WebhookEventType::PaymentSucceeded,
            provider: 'stripe',
            providerEventId: 'evt_test_early',
            payload: [
                'id' => 'in_test_early',
                'customer' => 'cus_test_early',
                'subscription' => 'sub_test_early',
                'payment_intent' => 'pi_test_early',
                'currency' => 'eur',
                'amount_paid' => 2900,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        try {
            $this->billingService->handleWebhook('stripe', request());
            $this->fail('Expected the handler to fail while the subscription is missing.');
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('webhook_events', ['provider_event_id' => 'evt_test_early', 'processed_at' => null]);
        Event::assertNotDispatched(PaymentSucceeded::class);

        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'provider_subscription_id' => 'sub_test_early',
        ]);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseHas('payments', [
            'subscription_id' => $subscription->id,
            'provider_payment_id' => 'pi_test_early',
            'status' => PaymentStatus::Succeeded->value,
        ]);
        $this->assertNotNull(WebhookEvent::where('provider_event_id', 'evt_test_early')->value('processed_at'));
        Event::assertDispatched(PaymentSucceeded::class, 1);
    }

    /**
     * A subscription belonging to a customer this app has no row for can never
     * be found, so failing would have the provider retry the event forever.
     */
    public function test_a_subscription_event_for_an_unknown_customer_is_acknowledged(): void
    {
        Event::fake([SubscriptionUpdated::class]);

        $this->gateway->method('verifyAndParseWebhook')->willReturn(new WebhookData(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_test_stranger',
            payload: [
                'id' => 'sub_test_stranger',
                'customer' => 'cus_test_stranger',
                'status' => 'active',
            ],
        ));

        $this->billingService->handleWebhook('stripe', request());

        $this->assertNotNull(WebhookEvent::where('provider_event_id', 'evt_test_stranger')->value('processed_at'));
        Event::assertNotDispatched(SubscriptionUpdated::class);
    }

    /**
     * The customer is known, so the subscription is still on its way: failing
     * keeps the event unreceipted and the provider retries it.
     */
    public function test_a_subscription_event_that_beats_its_subscription_is_retried(): void
    {
        Customer::factory()->create(['provider_customer_id' => 'cus_test_early_sub']);

        $this->gateway->method('verifyAndParseWebhook')->willReturn(new WebhookData(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_test_early_sub',
            payload: [
                'id' => 'sub_test_early_sub',
                'customer' => 'cus_test_early_sub',
                'status' => 'active',
            ],
        ));

        $this->expectException(\RuntimeException::class);

        try {
            $this->billingService->handleWebhook('stripe', request());
        } finally {
            $this->assertDatabaseHas('webhook_events', [
                'provider_event_id' => 'evt_test_early_sub',
                'processed_at' => null,
            ]);
        }
    }

    public function test_a_failed_payment_that_later_succeeds_updates_the_same_record(): void
    {
        Event::fake([PaymentFailed::class, PaymentSucceeded::class]);

        $customer = Customer::factory()->create(['provider_customer_id' => 'cus_test_retry']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'provider_subscription_id' => 'sub_test_retry',
            'status' => SubscriptionStatus::Active,
        ]);

        $invoice = [
            'id' => 'in_test_retry',
            'customer' => 'cus_test_retry',
            'subscription' => 'sub_test_retry',
            'payment_intent' => 'pi_test_retry',
            'currency' => 'eur',
            'amount_due' => 2900,
            'amount_paid' => 2900,
        ];

        $this->gateway->method('verifyAndParseWebhook')->willReturnOnConsecutiveCalls(
            new WebhookData(type: WebhookEventType::PaymentFailed, provider: 'stripe', providerEventId: 'evt_retry_1', payload: $invoice),
            new WebhookData(type: WebhookEventType::PaymentSucceeded, provider: 'stripe', providerEventId: 'evt_retry_2', payload: $invoice),
        );

        $this->billingService->handleWebhook('stripe', request());
        $this->assertEquals(SubscriptionStatus::PastDue, $subscription->fresh()->status);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', [
            'provider_payment_id' => 'pi_test_retry',
            'status' => PaymentStatus::Succeeded->value,
        ]);
        $this->assertEquals(SubscriptionStatus::Active, $subscription->fresh()->status);
        Event::assertDispatched(PaymentSucceeded::class, 1);
    }

    public function test_a_late_failure_does_not_undo_a_payment_that_succeeded(): void
    {
        Event::fake([PaymentFailed::class, PaymentSucceeded::class]);

        $customer = Customer::factory()->create(['provider_customer_id' => 'cus_test_late_fail']);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'provider_subscription_id' => 'sub_test_late_fail',
            'status' => SubscriptionStatus::Active,
        ]);

        $invoice = [
            'id' => 'in_test_late_fail',
            'customer' => 'cus_test_late_fail',
            'subscription' => 'sub_test_late_fail',
            'payment_intent' => 'pi_test_late_fail',
            'currency' => 'eur',
            'amount_due' => 2900,
            'amount_paid' => 2900,
        ];

        $this->gateway->method('verifyAndParseWebhook')->willReturnOnConsecutiveCalls(
            new WebhookData(type: WebhookEventType::PaymentSucceeded, provider: 'stripe', providerEventId: 'evt_late_1', payload: $invoice),
            new WebhookData(type: WebhookEventType::PaymentFailed, provider: 'stripe', providerEventId: 'evt_late_2', payload: $invoice),
        );

        $this->billingService->handleWebhook('stripe', request());
        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseHas('payments', ['provider_payment_id' => 'pi_test_late_fail', 'status' => PaymentStatus::Succeeded->value]);
        $this->assertEquals(SubscriptionStatus::Active, $subscription->fresh()->status);
        Event::assertDispatched(PaymentSucceeded::class, 1);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** The first hand-off is the one the buyer can pay; a second must not replace it. */
    public function test_reopening_a_checkout_reuses_the_provider_session(): void
    {
        $user = User::factory()->create();
        $price = Price::factory()->create();
        $session = CheckoutSession::create([
            'price_id' => $price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addHours(24),
        ]);

        $this->gateway->method('createCustomer')->willReturn('cus_test_reuse');
        $this->gateway->expects($this->once())->method('createCheckoutSession')->willReturn(
            new CheckoutResultData(sessionId: 'cs_first', url: 'https://stripe.com/first', provider: 'stripe'),
        );

        $this->billingService->processCheckout($session, $user, 'https://example.com/success', 'https://example.com/cancel');
        $again = $this->billingService->processCheckout($session->fresh(), $user, 'https://example.com/success', 'https://example.com/other', coupon: 'SAVE10');

        $this->assertSame('cs_first', $again->sessionId);
        $this->assertSame('https://stripe.com/first', $again->url);
    }

    /**
     * A delayed payment method completes the checkout before the money arrives.
     * Nothing is granted until the provider's follow-up says it has.
     */
    public function test_an_unpaid_checkout_is_fulfilled_only_when_the_payment_settles(): void
    {
        Event::fake([CheckoutCompleted::class, SubscriptionCreated::class, PaymentSucceeded::class]);

        $session = CheckoutSession::factory()->create(['provider_session_id' => 'cs_test_delayed']);

        $payload = [
            'id' => 'cs_test_delayed',
            'subscription' => 'sub_test_delayed',
            'currency' => 'eur',
            'amount_total' => 2900,
        ];

        $this->gateway->method('verifyAndParseWebhook')->willReturnOnConsecutiveCalls(
            new WebhookData(type: WebhookEventType::CheckoutCompleted, provider: 'stripe', providerEventId: 'evt_delayed_1', payload: $payload + ['payment_status' => 'unpaid']),
            new WebhookData(type: WebhookEventType::CheckoutCompleted, provider: 'stripe', providerEventId: 'evt_delayed_2', payload: $payload + ['payment_status' => 'paid']),
        );

        $this->billingService->handleWebhook('stripe', request());

        $this->assertEquals(CheckoutSessionStatus::Pending, $session->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 0);
        Event::assertNotDispatched(SubscriptionCreated::class);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertEquals(CheckoutSessionStatus::Completed, $session->fresh()->status);
        $this->assertDatabaseCount('subscriptions', 1);
        Event::assertDispatched(SubscriptionCreated::class, 1);
    }

    /** A trial or a fully discounted price owes nothing, and that is not a missing payment. */
    public function test_a_checkout_that_requires_no_payment_is_fulfilled(): void
    {
        Event::fake([CheckoutCompleted::class, SubscriptionCreated::class, PaymentSucceeded::class]);

        $session = CheckoutSession::factory()->create(['provider_session_id' => 'cs_test_trial']);

        $this->gateway->method('verifyAndParseWebhook')->willReturn(new WebhookData(
            type: WebhookEventType::CheckoutCompleted,
            provider: 'stripe',
            providerEventId: 'evt_trial',
            payload: [
                'id' => 'cs_test_trial',
                'subscription' => 'sub_test_trial',
                'payment_status' => 'no_payment_required',
                'currency' => 'eur',
                'amount_total' => 0,
            ],
        ));

        $this->billingService->handleWebhook('stripe', request());

        $this->assertEquals(CheckoutSessionStatus::Completed, $session->fresh()->status);
        Event::assertDispatched(SubscriptionCreated::class);
    }

    public function test_webhook_payment_succeeded_merges_into_checkout_created_payment(): void
    {
        Event::fake([CheckoutCompleted::class, SubscriptionCreated::class, PaymentSucceeded::class]);

        $session = CheckoutSession::factory()->create([
            'provider_session_id' => 'cs_test_merge',
        ]);

        // Step 1: Process checkout.session.completed (creates subscription + payment)
        $checkoutWebhook = new WebhookData(
            type: WebhookEventType::CheckoutCompleted,
            provider: 'stripe',
            providerEventId: 'evt_test_merge_checkout',
            payload: [
                'id' => 'cs_test_merge',
                'subscription' => 'sub_test_merge',
                'currency' => 'eur',
                'amount_total' => 2900,
            ],
        );

        // Step 2: invoice.payment_succeeded (should merge, not create duplicate)
        $invoiceWebhook = new WebhookData(
            type: WebhookEventType::PaymentSucceeded,
            provider: 'stripe',
            providerEventId: 'evt_test_merge_invoice',
            payload: [
                'id' => 'in_test_merge',
                'customer' => $session->customer->provider_customer_id,
                'subscription' => 'sub_test_merge',
                'default_payment_method' => 'pm_test_merge',
                'payment_intent' => 'pi_test_merge',
                'currency' => 'eur',
                'amount_paid' => 2900,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')
            ->willReturnOnConsecutiveCalls($checkoutWebhook, $invoiceWebhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription = Subscription::where('provider_subscription_id', 'sub_test_merge')->first();
        $this->assertNotNull($subscription);
        $this->assertDatabaseCount('payments', 1);

        // Payment created by checkout has no provider_payment_id
        $payment = Payment::where('subscription_id', $subscription->id)->first();
        $this->assertNull($payment->provider_payment_id);

        // Process invoice webhook
        $this->billingService->handleWebhook('stripe', request());

        // Still only 1 payment — not duplicated
        $this->assertDatabaseCount('payments', 1);

        // provider_payment_id is now filled in from the invoice webhook
        $payment->refresh();
        $this->assertEquals('pi_test_merge', $payment->provider_payment_id);

        // PaymentSucceeded dispatched only once (from checkout, not from invoice merge)
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_webhook_subscription_updated_updates_status(): void
    {
        Event::fake([SubscriptionUpdated::class]);

        $subscription = Subscription::factory()->create([
            'provider_subscription_id' => 'sub_test_update',
            'status' => SubscriptionStatus::Active,
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_test_2',
            payload: [
                'id' => 'sub_test_update',
                'status' => 'past_due',
                'default_payment_method' => 'pm_test_sub_update',
                'current_period_start' => 1700000000,
                'current_period_end' => 1702592000,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertNotNull($subscription->payment_method_id);
        $this->assertDatabaseHas('payment_methods', [
            'provider_payment_method_id' => 'pm_test_123',
        ]);

        Event::assertDispatched(SubscriptionUpdated::class);
    }

    public function test_webhook_subscription_updated_handles_cancel_at_scheduled_cancellation(): void
    {
        Event::fake([SubscriptionUpdated::class]);

        $subscription = Subscription::factory()->create([
            'provider_subscription_id' => 'sub_test_cancel_at',
            'status' => SubscriptionStatus::Active,
        ]);

        $cancelAt = now()->addYear()->getTimestamp();

        $webhook = new WebhookData(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_test_cancel_at',
            payload: [
                'id' => 'sub_test_cancel_at',
                'status' => 'active',
                'cancel_at_period_end' => false,
                'cancel_at' => $cancelAt,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::Active, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertNotNull($subscription->ends_at);
        $this->assertEquals($cancelAt, $subscription->ends_at->getTimestamp());

        Event::assertDispatched(SubscriptionUpdated::class);
    }

    public function test_webhook_subscription_updated_cancel_at_period_end_falls_back_to_cancel_at(): void
    {
        Event::fake([SubscriptionUpdated::class]);

        $subscription = Subscription::factory()->create([
            'provider_subscription_id' => 'sub_test_cancel_fallback',
            'status' => SubscriptionStatus::Active,
        ]);

        $cancelAt = now()->addMonth()->getTimestamp();

        $webhook = new WebhookData(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_test_cancel_fallback',
            payload: [
                'id' => 'sub_test_cancel_fallback',
                'status' => 'active',
                'cancel_at_period_end' => true,
                'cancel_at' => $cancelAt,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::Active, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertNotNull($subscription->ends_at);
        $this->assertEquals($cancelAt, $subscription->ends_at->getTimestamp());

        Event::assertDispatched(SubscriptionUpdated::class);
    }

    #[DataProvider('stripeStatusMappingProvider')]
    public function test_webhook_subscription_updated_maps_stripe_statuses(string $stripeStatus, SubscriptionStatus $expectedStatus): void
    {
        Event::fake([SubscriptionUpdated::class]);

        $subscription = Subscription::factory()->create([
            'provider_subscription_id' => 'sub_test_status_map',
            'status' => SubscriptionStatus::Active,
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_test_status',
            payload: [
                'id' => 'sub_test_status_map',
                'status' => $stripeStatus,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription->refresh();
        $this->assertEquals($expectedStatus, $subscription->status);

        Event::assertDispatched(SubscriptionUpdated::class);
    }

    /**
     * @return array<string, array{string, SubscriptionStatus}>
     */
    public static function stripeStatusMappingProvider(): array
    {
        return [
            'active' => ['active', SubscriptionStatus::Active],
            'trialing' => ['trialing', SubscriptionStatus::Active],
            'past_due' => ['past_due', SubscriptionStatus::PastDue],
            'unpaid' => ['unpaid', SubscriptionStatus::PastDue],
            'canceled' => ['canceled', SubscriptionStatus::Cancelled],
            'incomplete_expired' => ['incomplete_expired', SubscriptionStatus::Cancelled],
            'incomplete' => ['incomplete', SubscriptionStatus::Pending],
            'paused' => ['paused', SubscriptionStatus::Pending],
        ];
    }

    public function test_a_cancelled_subscription_ignores_a_late_active_update(): void
    {
        Event::fake([SubscriptionUpdated::class]);

        $subscription = Subscription::factory()->create([
            'provider_subscription_id' => 'sub_test_late',
            'status' => SubscriptionStatus::Cancelled,
        ]);

        $this->gateway->method('verifyAndParseWebhook')->willReturn(new WebhookData(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_late',
            payload: ['id' => 'sub_test_late', 'status' => 'active'],
        ));

        $this->billingService->handleWebhook('stripe', request());

        $this->assertEquals(SubscriptionStatus::Cancelled, $subscription->fresh()->status);
        Event::assertNotDispatched(SubscriptionUpdated::class);
    }

    /** Stripe delivers out of order; a stale event must not undo a newer one. */
    public function test_an_older_subscription_event_does_not_overwrite_a_newer_one(): void
    {
        Event::fake([SubscriptionUpdated::class]);

        $subscription = Subscription::factory()->create([
            'provider_subscription_id' => 'sub_test_order',
            'status' => SubscriptionStatus::PastDue,
            'last_event_at' => now(),
        ]);

        $this->gateway->method('verifyAndParseWebhook')->willReturn(new WebhookData(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_order_old',
            payload: ['id' => 'sub_test_order', 'status' => 'active'],
            occurredAt: CarbonImmutable::now()->subMinute(),
        ));

        $this->billingService->handleWebhook('stripe', request());

        $this->assertEquals(SubscriptionStatus::PastDue, $subscription->fresh()->status);
        Event::assertNotDispatched(SubscriptionUpdated::class);
    }

    /** Two providers can hand out the same ID; only this provider's row may match. */
    public function test_provider_ids_are_scoped_to_their_provider(): void
    {
        Customer::factory()->create(['provider_customer_id' => 'cus_scoped']);
        Subscription::factory()->create([
            'provider' => 'other',
            'provider_subscription_id' => 'sub_shared',
        ]);

        $this->gateway->method('verifyAndParseWebhook')->willReturn(new WebhookData(
            type: WebhookEventType::SubscriptionUpdated,
            provider: 'stripe',
            providerEventId: 'evt_scoped',
            payload: ['id' => 'sub_shared', 'customer' => 'cus_scoped', 'status' => 'canceled'],
        ));

        $this->expectException(\RuntimeException::class);

        $this->billingService->handleWebhook('stripe', request());
    }

    public function test_webhook_subscription_deleted_cancels_subscription(): void
    {
        Event::fake([SubscriptionCancelled::class]);

        $subscription = Subscription::factory()->create([
            'provider_subscription_id' => 'sub_test_delete',
            'status' => SubscriptionStatus::Active,
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::SubscriptionDeleted,
            provider: 'stripe',
            providerEventId: 'evt_test_3',
            payload: [
                'id' => 'sub_test_delete',
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::Cancelled, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);

        Event::assertDispatched(SubscriptionCancelled::class);
    }

    public function test_webhook_payment_succeeded_creates_payment(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $customer = Customer::factory()->create([
            'provider_customer_id' => 'cus_test_pay',
        ]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'provider_subscription_id' => 'sub_test_pay',
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::PaymentSucceeded,
            provider: 'stripe',
            providerEventId: 'evt_test_4',
            payload: [
                'id' => 'in_test_123',
                'customer' => 'cus_test_pay',
                'subscription' => 'sub_test_pay',
                'default_payment_method' => 'pm_test_pay',
                'payment_intent' => 'pi_test_123',
                'currency' => 'usd',
                'amount_paid' => 2900,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseHas('payment_methods', [
            'provider_payment_method_id' => 'pm_test_123',
            'customer_id' => $customer->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'provider_payment_id' => 'pi_test_123',
            'amount' => 2900,
            'status' => PaymentStatus::Succeeded->value,
        ]);

        $payment = Payment::where('provider_payment_id', 'pi_test_123')->first();
        $this->assertNotNull($payment->payment_method_id);

        Event::assertDispatched(PaymentSucceeded::class);
    }

    public function test_webhook_payment_failed_marks_subscription_past_due(): void
    {
        Event::fake([PaymentFailed::class]);

        $customer = Customer::factory()->create([
            'provider_customer_id' => 'cus_test_fail',
        ]);
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'provider_subscription_id' => 'sub_test_fail',
            'status' => SubscriptionStatus::Active,
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::PaymentFailed,
            provider: 'stripe',
            providerEventId: 'evt_test_5',
            payload: [
                'id' => 'in_test_456',
                'customer' => 'cus_test_fail',
                'subscription' => 'sub_test_fail',
                'default_payment_method' => 'pm_test_fail',
                'payment_intent' => 'pi_test_456',
                'currency' => 'usd',
                'amount_due' => 2900,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::PastDue, $subscription->status);

        $this->assertDatabaseHas('payment_methods', [
            'provider_payment_method_id' => 'pm_test_123',
            'customer_id' => $customer->id,
        ]);
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'status' => PaymentStatus::Failed->value,
        ]);

        $payment = Payment::where('provider_payment_id', 'pi_test_456')->first();
        $this->assertNotNull($payment->payment_method_id);

        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_webhook_invoice_paid_creates_invoice(): void
    {
        Event::fake([InvoicePaid::class]);

        $customer = Customer::factory()->create([
            'provider_customer_id' => 'cus_test_inv',
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::InvoicePaid,
            provider: 'stripe',
            providerEventId: 'evt_test_6',
            payload: [
                'id' => 'in_test_invoice',
                'customer' => 'cus_test_inv',
                'number' => 'INV-001',
                'currency' => 'usd',
                'subtotal' => 2900,
                'tax' => 0,
                'total' => 2900,
                'hosted_invoice_url' => 'https://stripe.com/invoice/123',
                'invoice_pdf' => 'https://stripe.com/invoice/123/pdf',
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseHas('invoices', [
            'customer_id' => $customer->id,
            'provider_invoice_id' => 'in_test_invoice',
            'number' => 'INV-001',
            'total' => 2900,
        ]);

        Event::assertDispatched(InvoicePaid::class);
    }

    public function test_webhook_invoice_paid_syncs_subscription_period_from_line_items(): void
    {
        Event::fake([InvoicePaid::class]);

        $customer = Customer::factory()->create([
            'provider_customer_id' => 'cus_test_inv_period',
        ]);

        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'provider_subscription_id' => 'sub_test_inv_period',
            'current_period_starts_at' => null,
            'current_period_ends_at' => null,
        ]);

        $periodStart = 1770827927;
        $periodEnd = 1773247127;

        $webhook = new WebhookData(
            type: WebhookEventType::InvoicePaid,
            provider: 'stripe',
            providerEventId: 'evt_test_inv_period',
            payload: [
                'id' => 'in_test_inv_period',
                'customer' => 'cus_test_inv_period',
                'subscription' => 'sub_test_inv_period',
                'number' => 'INV-002',
                'currency' => 'usd',
                'subtotal' => 2900,
                'tax' => 0,
                'total' => 2900,
                'hosted_invoice_url' => 'https://stripe.com/invoice/456',
                'invoice_pdf' => 'https://stripe.com/invoice/456/pdf',
                'lines' => [
                    'data' => [
                        [
                            'period' => [
                                'start' => $periodStart,
                                'end' => $periodEnd,
                            ],
                        ],
                    ],
                ],
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $subscription->refresh();
        $this->assertNotNull($subscription->current_period_starts_at);
        $this->assertNotNull($subscription->current_period_ends_at);
        $this->assertEquals($periodStart, $subscription->current_period_starts_at->getTimestamp());
        $this->assertEquals($periodEnd, $subscription->current_period_ends_at->getTimestamp());

        Event::assertDispatched(InvoicePaid::class);
    }

    public function test_webhook_invoice_paid_resolves_subscription_from_parent_field(): void
    {
        Event::fake([InvoicePaid::class]);

        $customer = Customer::factory()->create([
            'provider_customer_id' => 'cus_test_inv_parent',
        ]);

        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'provider_subscription_id' => 'sub_test_inv_parent',
            'current_period_starts_at' => null,
            'current_period_ends_at' => null,
        ]);

        $periodStart = 1770827927;
        $periodEnd = 1773247127;

        $webhook = new WebhookData(
            type: WebhookEventType::InvoicePaid,
            provider: 'stripe',
            providerEventId: 'evt_test_inv_parent',
            payload: [
                'id' => 'in_test_inv_parent',
                'customer' => 'cus_test_inv_parent',
                'parent' => [
                    'subscription_details' => [
                        'subscription' => 'sub_test_inv_parent',
                    ],
                ],
                'number' => 'INV-003',
                'currency' => 'eur',
                'subtotal' => 2900,
                'tax' => 0,
                'total' => 2900,
                'hosted_invoice_url' => 'https://stripe.com/invoice/789',
                'invoice_pdf' => 'https://stripe.com/invoice/789/pdf',
                'lines' => [
                    'data' => [
                        [
                            'period' => [
                                'start' => $periodStart,
                                'end' => $periodEnd,
                            ],
                        ],
                    ],
                ],
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseHas('invoices', [
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'provider_invoice_id' => 'in_test_inv_parent',
        ]);

        $subscription->refresh();
        $this->assertNotNull($subscription->current_period_ends_at);
        $this->assertEquals($periodEnd, $subscription->current_period_ends_at->getTimestamp());

        Event::assertDispatched(InvoicePaid::class);
    }

    public function test_webhook_payment_succeeded_ignores_unknown_customer(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $webhook = new WebhookData(
            type: WebhookEventType::PaymentSucceeded,
            provider: 'stripe',
            providerEventId: 'evt_no_customer',
            payload: [
                'id' => 'in_unknown',
                'customer' => 'cus_nonexistent',
                'currency' => 'usd',
                'amount_paid' => 1000,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseCount('payments', 0);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_webhook_payment_failed_ignores_unknown_customer(): void
    {
        Event::fake([PaymentFailed::class]);

        $webhook = new WebhookData(
            type: WebhookEventType::PaymentFailed,
            provider: 'stripe',
            providerEventId: 'evt_no_customer_fail',
            payload: [
                'id' => 'in_unknown_fail',
                'customer' => 'cus_nonexistent',
                'currency' => 'usd',
                'amount_due' => 1000,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseCount('payments', 0);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function test_webhook_invoice_paid_ignores_unknown_customer(): void
    {
        Event::fake([InvoicePaid::class]);

        $webhook = new WebhookData(
            type: WebhookEventType::InvoicePaid,
            provider: 'stripe',
            providerEventId: 'evt_no_customer_inv',
            payload: [
                'id' => 'in_unknown_inv',
                'customer' => 'cus_nonexistent',
                'currency' => 'usd',
                'subtotal' => 1000,
                'tax' => 0,
                'total' => 1000,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseCount('invoices', 0);
        Event::assertNotDispatched(InvoicePaid::class);
    }

    public function test_webhook_checkout_completed_ignores_unknown_session(): void
    {
        Event::fake([CheckoutCompleted::class]);

        $webhook = new WebhookData(
            type: WebhookEventType::CheckoutCompleted,
            provider: 'stripe',
            providerEventId: 'evt_unknown_session',
            payload: [
                'id' => 'cs_nonexistent',
                'subscription' => 'sub_test',
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseCount('subscriptions', 0);
        Event::assertNotDispatched(CheckoutCompleted::class);
    }

    public function test_webhook_unmapped_type_does_nothing(): void
    {
        $webhook = new WebhookData(
            type: null,
            provider: 'stripe',
            providerEventId: 'evt_unmapped',
            payload: ['id' => 'obj_123'],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseHas('webhook_events', [
            'provider_event_id' => 'evt_unmapped',
            'type' => 'unmapped',
        ]);
    }

    public function test_webhook_invoice_paid_is_idempotent(): void
    {
        Event::fake([InvoicePaid::class]);

        $customer = Customer::factory()->create(['provider_customer_id' => 'cus_idempotent']);

        $payload = [
            'id' => 'in_idempotent',
            'customer' => 'cus_idempotent',
            'number' => 'INV-IDEM',
            'currency' => 'usd',
            'subtotal' => 2900,
            'tax' => 0,
            'total' => 2900,
            'hosted_invoice_url' => 'https://stripe.com/invoice/idem',
            'invoice_pdf' => 'https://stripe.com/invoice/idem/pdf',
        ];

        $webhook = new WebhookData(
            type: WebhookEventType::InvoicePaid,
            provider: 'stripe',
            providerEventId: 'evt_idem_1',
            payload: $payload,
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());
        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoices', [
            'provider_invoice_id' => 'in_idempotent',
            'customer_id' => $customer->id,
        ]);
    }

    public function test_process_checkout_updates_existing_customer(): void
    {
        $user = User::factory()->create();
        $existingCustomer = Customer::create([
            'provider' => 'stripe',
            'user_id' => $user->id,
            'provider_customer_id' => 'cus_existing',
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);
        $price = Price::factory()->create();
        $session = CheckoutSession::create([
            'price_id' => $price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addHours(24),
        ]);

        $this->gateway->method('createCheckoutSession')->willReturn(
            new CheckoutResultData(sessionId: 'cs_update', url: 'https://stripe.com/checkout', provider: 'stripe'),
        );

        $this->billingService->processCheckout($session, $user, 'https://example.com/success', 'https://example.com/cancel', [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ]);

        $existingCustomer->refresh();
        $this->assertEquals('New Name', $existingCustomer->name);
        $this->assertEquals('new@example.com', $existingCustomer->email);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_process_checkout_gives_an_existing_customer_without_a_provider_id_one(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->withoutProvider()->create(['user_id' => $user->id]);
        $price = Price::factory()->create();
        $session = CheckoutSession::create([
            'price_id' => $price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addHours(24),
        ]);

        $this->gateway->expects($this->once())->method('createCustomer')->willReturn('cus_created');
        $this->gateway->method('createCheckoutSession')->willReturn(
            new CheckoutResultData(sessionId: 'cs_created', url: 'https://stripe.com/checkout', provider: 'stripe'),
        );

        $this->billingService->processCheckout($session, $user, 'https://example.com/success', 'https://example.com/cancel');

        $this->assertSame('cus_created', $customer->refresh()->provider_customer_id);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_ensure_payment_method_reuses_existing_default(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $customer = Customer::factory()->create(['provider_customer_id' => 'cus_pm_default']);

        PaymentMethod::create([
            'provider' => 'stripe',
            'customer_id' => $customer->id,
            'provider_payment_method_id' => 'pm_test_123',
            'type' => PaymentMethodType::Card,
            'details' => ['brand' => 'visa', 'last4' => '4242', 'expMonth' => 12, 'expYear' => 2030],
            'is_default' => true,
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::PaymentSucceeded,
            provider: 'stripe',
            providerEventId: 'evt_pm_reuse',
            payload: [
                'id' => 'in_pm_reuse',
                'customer' => 'cus_pm_default',
                'default_payment_method' => 'pm_test_123',
                'payment_intent' => 'pi_pm_reuse',
                'currency' => 'usd',
                'amount_paid' => 500,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseCount('payment_methods', 1);

        $payment = Payment::where('provider_payment_id', 'pi_pm_reuse')->first();
        $this->assertNotNull($payment->payment_method_id);
    }

    public function test_ensure_payment_method_swaps_default(): void
    {
        Event::fake([PaymentSucceeded::class]);

        $customer = Customer::factory()->create(['provider_customer_id' => 'cus_pm_swap']);

        $oldPm = PaymentMethod::create([
            'provider' => 'stripe',
            'customer_id' => $customer->id,
            'provider_payment_method_id' => 'pm_old_default',
            'type' => PaymentMethodType::Card,
            'details' => ['brand' => 'mastercard', 'last4' => '5555', 'expMonth' => 6, 'expYear' => 2028],
            'is_default' => true,
        ]);

        $webhook = new WebhookData(
            type: WebhookEventType::PaymentSucceeded,
            provider: 'stripe',
            providerEventId: 'evt_pm_swap',
            payload: [
                'id' => 'in_pm_swap',
                'customer' => 'cus_pm_swap',
                'default_payment_method' => 'pm_new',
                'payment_intent' => 'pi_pm_swap',
                'currency' => 'usd',
                'amount_paid' => 500,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $oldPm->refresh();
        $this->assertFalse($oldPm->is_default);

        $newPm = PaymentMethod::where('provider_payment_method_id', 'pm_test_123')->first();
        $this->assertTrue($newPm->is_default);
        $this->assertDatabaseCount('payment_methods', 2);
    }

    public function test_webhook_payment_succeeded_handles_zero_amount(): void
    {
        Event::fake([PaymentSucceeded::class]);

        Customer::factory()->create(['provider_customer_id' => 'cus_zero']);

        $webhook = new WebhookData(
            type: WebhookEventType::PaymentSucceeded,
            provider: 'stripe',
            providerEventId: 'evt_zero',
            payload: [
                'id' => 'in_zero',
                'customer' => 'cus_zero',
                'payment_intent' => 'pi_zero',
                'currency' => 'usd',
                'amount_paid' => 0,
            ],
        );

        $this->gateway->method('verifyAndParseWebhook')->willReturn($webhook);

        $this->billingService->handleWebhook('stripe', request());

        $this->assertDatabaseHas('payments', [
            'provider_payment_id' => 'pi_zero',
            'amount' => 0,
            'status' => PaymentStatus::Succeeded->value,
        ]);

        Event::assertDispatched(PaymentSucceeded::class);
    }
}
