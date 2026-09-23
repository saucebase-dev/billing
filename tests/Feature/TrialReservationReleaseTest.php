<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * A checkout holds the customer's one trial until the provider confirms that
 * checkout can never be paid. Local bookkeeping alone proves nothing: the
 * hosted page is still payable until the provider says otherwise.
 */
class TrialReservationReleaseTest extends TestCase
{
    use RefreshDatabase;

    /** @var StripeGateway&MockObject */
    private StripeGateway $gateway;

    private Customer $customer;

    private Price $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(StripeGateway::class);

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->customer = Customer::factory()->create();
        $this->price = Price::factory()->create([
            'product_id' => Product::factory()->create(['trial_days' => 14])->id,
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function heldTrial(array $attributes = []): CheckoutSession
    {
        // `created_at` is not fillable, so an aged session is dated afterwards.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $session = CheckoutSession::create([
            'customer_id' => $this->customer->id,
            'price_id' => $this->price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->subHour(),
            'provider' => 'stripe',
            'provider_session_id' => 'cs_held',
            'provider_url' => 'https://provider.test/cs_held',
            // Checkout stores the request with the trial, in one write.
            'success_url' => 'https://app.test/ok',
            'cancel_url' => 'https://app.test/no',
            'trial_days' => 14,
            'trial_requires_payment_method' => true,
            ...$attributes,
        ]);

        if ($createdAt) {
            $session->forceFill(['created_at' => $createdAt])->save();
        }

        return $session;
    }

    public function test_a_trial_is_released_once_the_provider_expires_the_checkout(): void
    {
        $session = $this->heldTrial();
        $this->gateway->expects($this->once())->method('expireCheckoutSession')->with('cs_held')->willReturn(true);

        $this->artisan('billing:expire-checkout-sessions');

        $this->assertSame(CheckoutSessionStatus::Expired, $session->fresh()->status);
        $this->assertFalse($this->customer->hasTrialed());
    }

    /** The hosted page may still be paid, so the claim stands. */
    public function test_a_trial_stays_held_when_the_provider_will_not_expire_it(): void
    {
        $session = $this->heldTrial();
        $this->gateway->method('expireCheckoutSession')->willReturn(false);

        $this->artisan('billing:expire-checkout-sessions');

        $this->assertSame(CheckoutSessionStatus::Pending, $session->fresh()->status);
        $this->assertTrue($this->customer->hasTrialed());
    }

    /**
     * The hand-off crashed, so no provider ID was stored — but the provider may
     * well have made the session. Replaying the same idempotency key finds it.
     */
    public function test_a_checkout_with_no_provider_id_is_resolved_before_being_released(): void
    {
        $session = $this->heldTrial(['provider_session_id' => null, 'provider_url' => null]);

        $this->gateway->expects($this->once())->method('createCheckoutSession')
            ->with($this->callback(fn (CheckoutData $data) => $data->idempotencyKey === 'checkout_'.$session->uuid))
            ->willReturn(new CheckoutResultData(sessionId: 'cs_recovered', url: 'https://provider.test/cs_recovered', provider: 'stripe'));
        $this->gateway->expects($this->once())->method('expireCheckoutSession')->with('cs_recovered')->willReturn(true);

        $this->artisan('billing:expire-checkout-sessions');

        $this->assertSame(CheckoutSessionStatus::Expired, $session->fresh()->status);
        $this->assertFalse($this->customer->hasTrialed());
    }

    /** Past the provider's idempotency window the original outcome is unknowable. */
    public function test_an_old_unresolvable_checkout_keeps_its_claim(): void
    {
        $session = $this->heldTrial([
            'provider_session_id' => null,
            'provider_url' => null,
            'created_at' => now()->subDays(2),
        ]);

        $this->gateway->expects($this->never())->method('createCheckoutSession');

        $this->artisan('billing:expire-checkout-sessions');

        $this->assertSame(CheckoutSessionStatus::Pending, $session->fresh()->status);
        $this->assertTrue($this->customer->hasTrialed());
    }

    public function test_a_checkout_holding_no_trial_expires_without_asking_the_provider(): void
    {
        $session = $this->heldTrial(['trial_days' => 0]);

        $this->gateway->expects($this->never())->method('expireCheckoutSession');

        $this->artisan('billing:expire-checkout-sessions');

        $this->assertSame(CheckoutSessionStatus::Expired, $session->fresh()->status);
    }

    public function test_an_abandoned_trial_checkout_is_resolved_the_same_way(): void
    {
        $session = $this->heldTrial([
            'expires_at' => now()->addDay(),
            'created_at' => now()->subHours(3),
        ]);
        $this->gateway->method('expireCheckoutSession')->willReturn(true);

        $this->artisan('billing:expire-checkout-sessions');

        $this->assertSame(CheckoutSessionStatus::Abandoned, $session->fresh()->status);
        $this->assertFalse($this->customer->hasTrialed());
    }

    /** Found once, kept: the replay only works for a day, the expiry for ever. */
    public function test_a_resolved_provider_id_is_kept_even_when_the_checkout_stays_open(): void
    {
        $session = $this->heldTrial(['provider_session_id' => null, 'provider_url' => null]);
        $this->gateway->method('createCheckoutSession')
            ->willReturn(new CheckoutResultData(sessionId: 'cs_recovered', url: 'https://provider.test/cs_recovered', provider: 'stripe'));
        $this->gateway->method('expireCheckoutSession')->willReturn(false);

        $this->artisan('billing:expire-checkout-sessions');

        $this->assertSame('cs_recovered', $session->fresh()->provider_session_id);
    }
}
