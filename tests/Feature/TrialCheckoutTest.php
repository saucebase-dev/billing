<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Settings\BillingSettings;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * A plan's trial is offered once per customer, and the decision is made before
 * the provider is called — never read back afterwards from what happened.
 */
class TrialCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $billing;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    private User $user;

    private Price $price;

    /** @var list<CheckoutData> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(StripeGateway::class);
        $this->gateway->method('createCustomer')->willReturn('cus_trial');
        $this->gateway->method('createCheckoutSession')->willReturnCallback(function (CheckoutData $data): CheckoutResultData {
            $this->sent[] = $data;

            return new CheckoutResultData(sessionId: 'cs_trial_'.count($this->sent), url: 'https://provider.test/'.count($this->sent), provider: 'stripe');
        });

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->billing = app(BillingService::class);
        $this->user = $this->createUser();
        $this->price = Price::factory()->create([
            'product_id' => Product::factory()->create(['trial_days' => 14])->id,
        ]);
    }

    private function checkout(?Price $price = null): CheckoutSession
    {
        $session = CheckoutSession::create([
            'price_id' => ($price ?? $this->price)->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        $this->billing->processCheckout($session, $this->user, $this->user, 'https://app.test/ok', 'https://app.test/no');

        return $session->fresh();
    }

    private function lastSent(): CheckoutData
    {
        return $this->sent[count($this->sent) - 1];
    }

    public function test_a_plans_trial_reaches_the_provider(): void
    {
        $this->checkout();

        $this->assertSame(14, $this->lastSent()->trialDays);
    }

    public function test_a_plan_without_a_trial_asks_for_none(): void
    {
        $this->checkout(Price::factory()->create(['product_id' => Product::factory()->create(['trial_days' => null])->id]));

        $this->assertNull($this->lastSent()->trialDays);
    }

    /** The decision is written down, so nobody has to infer it later. */
    public function test_the_decision_is_recorded_on_the_session(): void
    {
        $this->assertSame(14, $this->checkout()->trial_days);
    }

    public function test_a_customer_who_already_trialed_pays_from_the_start(): void
    {
        $customer = Customer::factory()->for($this->user, 'owner')->create();
        // A trial they took last year and have since cancelled.
        Subscription::factory()->cancelled()->create([
            'customer_id' => $customer->id,
            'trial_starts_at' => now()->subYear(),
            'trial_ends_at' => now()->subYear()->addDays(14),
        ]);

        $session = $this->checkout();

        $this->assertNull($this->lastSent()->trialDays);
        $this->assertSame(0, $session->trial_days);
    }

    /** A second checkout while the first is still open cannot take the trial twice. */
    public function test_a_trial_held_by_another_open_session_is_not_given_again(): void
    {
        $this->checkout();

        $this->checkout();

        $this->assertSame(14, $this->sent[0]->trialDays);
        $this->assertNull($this->sent[1]->trialDays);
    }

    /** The provider timed out; the retry must offer what the buyer was promised. */
    public function test_a_retried_session_keeps_its_own_decision(): void
    {
        $session = $this->checkout();
        $session->update(['provider_url' => null, 'provider_session_id' => null]);

        $this->billing->processCheckout($session, $this->user, $this->user, 'https://app.test/ok', 'https://app.test/no');

        $this->assertSame(14, $this->lastSent()->trialDays);
    }

    public function test_payment_details_are_required_by_default(): void
    {
        $this->checkout();

        $this->assertTrue($this->lastSent()->trialRequiresPaymentMethod);
    }

    public function test_the_setting_can_make_payment_details_optional(): void
    {
        app(BillingSettings::class)->fill(['trial_requires_payment_method' => false])->save();

        $this->checkout();

        $this->assertFalse($this->lastSent()->trialRequiresPaymentMethod);
    }

    /** Flipping the setting must not change a checkout already issued. */
    public function test_a_retried_session_keeps_the_collection_it_was_issued_with(): void
    {
        $session = $this->checkout();
        $session->update(['provider_url' => null, 'provider_session_id' => null]);

        app(BillingSettings::class)->fill(['trial_requires_payment_method' => false])->save();
        $this->billing->processCheckout($session, $this->user, $this->user, 'https://app.test/ok', 'https://app.test/no');

        $this->assertTrue($this->lastSent()->trialRequiresPaymentMethod);
    }

    /** A replay under the same idempotency key must send what this call sent. */
    public function test_the_request_is_stored_before_the_hand_off(): void
    {
        $session = CheckoutSession::create([
            'price_id' => $this->price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);
        $this->gateway = $this->createMock(StripeGateway::class);
        $this->gateway->method('createCustomer')->willReturn('cus_trial');
        $this->gateway->method('createCheckoutSession')->willThrowException(new \RuntimeException('timeout'));
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
        app()->forgetInstance(BillingService::class);

        try {
            app(BillingService::class)->processCheckout($session, $this->user, $this->user, 'https://app.test/ok', 'https://app.test/no', coupon: 'SAVE10');
        } catch (\RuntimeException) {
        }

        $session->refresh();
        $this->assertSame('https://app.test/ok', $session->success_url);
        $this->assertSame('https://app.test/no', $session->cancel_url);
        $this->assertSame('SAVE10', $session->coupon);
    }

    /** Two requests loaded the session before either decided; the second must not undo the first. */
    public function test_a_request_holding_a_stale_session_keeps_the_trial_reserved(): void
    {
        $session = CheckoutSession::create([
            'price_id' => $this->price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);
        $stale = $session->fresh();

        $this->billing->processCheckout($session, $this->user, $this->user, 'https://app.test/ok', 'https://app.test/no');
        $this->billing->processCheckout($stale, $this->user, $this->user, 'https://app.test/ok', 'https://app.test/no');

        $this->assertSame(14, $session->fresh()->trial_days);
        $this->assertSame(14, $this->lastSent()->trialDays);
    }

    /** The idempotency key promises the same request, so a retry cannot change it. */
    public function test_a_retry_sends_the_first_request_again(): void
    {
        $session = CheckoutSession::create([
            'price_id' => $this->price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);
        $this->billing->processCheckout($session, $this->user, $this->user, 'https://app.test/ok', 'https://app.test/no', coupon: 'FIRST');
        $session->update(['provider_url' => null, 'provider_session_id' => null]);

        $this->billing->processCheckout($session->fresh(), $this->user, $this->user, 'https://app.test/other', 'https://app.test/other', coupon: 'SECOND');

        $this->assertSame('FIRST', $this->lastSent()->coupon);
        $this->assertSame('https://app.test/ok', $this->lastSent()->successUrl);
        $this->assertSame('FIRST', $session->fresh()->coupon);
    }

    /** A checkout finished while this request waited must not be handed off again. */
    public function test_a_checkout_no_longer_pending_is_not_handed_off(): void
    {
        $session = CheckoutSession::create([
            'price_id' => $this->price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);
        $stale = $session->fresh();
        $session->update(['status' => CheckoutSessionStatus::Expired]);

        try {
            $this->billing->processCheckout($stale, $this->user, $this->user, 'https://app.test/ok', 'https://app.test/no');
            $this->fail('An expired checkout was handed off.');
        } catch (AuthorizationException) {
        }

        $this->assertSame([], $this->sent);
    }
}
