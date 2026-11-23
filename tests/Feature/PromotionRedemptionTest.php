<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Actions\CompleteCheckout;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Promotion;
use Modules\Billing\Services\Gateways\StripeEventMapper;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use Tests\TestCase;

/** The completed checkout counts the redemption, once, and the payment says what was taken off. */
class PromotionRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private Promotion $promotion;

    private CheckoutSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $gateway = $this->createStub(StripeGateway::class);
        $manager = $this->createStub(PaymentGatewayManager::class);
        $manager->method('driver')->willReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->promotion = Promotion::factory()->create([
            'provider_promotion_code_id' => 'promo_launch',
            'code' => 'LAUNCH',
            'max_redemptions' => 50,
            'times_redeemed' => 49,
        ]);
        $this->session = CheckoutSession::factory()->create([
            'price_id' => Price::factory()->oneTime()->create(['amount' => 17900, 'promotion_id' => $this->promotion->id])->id,
            'provider_session_id' => 'cs_launch',
            'coupon' => 'LAUNCH',
        ]);
    }

    /** @param  array<string, mixed>  $session */
    private function complete(array $session = []): void
    {
        app(CompleteCheckout::class)->handle('stripe', StripeEventMapper::checkoutSession([
            'id' => 'cs_launch',
            'status' => 'complete',
            'payment_status' => 'paid',
            'payment_intent' => 'pi_launch',
            'currency' => 'usd',
            'amount_total' => 14900,
            'total_details' => ['amount_discount' => 3000, 'amount_shipping' => 0, 'amount_tax' => 0],
            'discounts' => [['coupon' => 'co_launch', 'promotion_code' => 'promo_launch']],
            ...$session,
        ]));
    }

    public function test_a_discounted_checkout_counts_one_redemption(): void
    {
        $this->complete();

        $this->assertSame(50, $this->promotion->fresh()->times_redeemed);
        // The 50th: the page goes back to the regular price on the next render.
        $this->assertNull(Price::with(['promotion' => fn ($q) => $q->available()])->find($this->session->price_id)->promotion);
    }

    /** The webhook, its retry and the buyer's return page all complete the same checkout. */
    public function test_completing_again_counts_nothing_more(): void
    {
        $this->complete();
        $this->complete();

        $this->assertSame(50, $this->promotion->fresh()->times_redeemed);
        $this->assertSame(1, Payment::count());
    }

    public function test_the_payment_records_the_discount_and_the_code(): void
    {
        $this->complete();

        $payment = Payment::sole();
        $this->assertSame(14900, $payment->amount);
        $this->assertSame(3000, $payment->discount_amount);
        $this->assertSame('LAUNCH', $payment->promotion_code);
    }

    /** Stripe turned the code down and the buyer paid in full: nothing was redeemed. */
    public function test_a_checkout_without_the_discount_counts_nothing(): void
    {
        $this->complete(['amount_total' => 17900, 'total_details' => ['amount_discount' => 0], 'discounts' => []]);

        $this->assertSame(49, $this->promotion->fresh()->times_redeemed);
        $this->assertSame(0, Payment::sole()->discount_amount);
        $this->assertNull(Payment::sole()->promotion_code);
    }

    /** A code this app does not present still shows on the payment as a discount. */
    public function test_another_code_is_recorded_but_not_counted(): void
    {
        $this->complete(['discounts' => [['coupon' => 'co_friend', 'promotion_code' => 'promo_friend']]]);

        $this->assertSame(49, $this->promotion->fresh()->times_redeemed);
        $this->assertSame(3000, Payment::sole()->discount_amount);
        // Not LAUNCH, which checkout offered: Stripe applied something else.
        $this->assertNull(Payment::sole()->promotion_code);
    }
}
