<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Actions\StartCheckout;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Promotion;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Settings\BillingSettings;
use Modules\Billing\Tests\Support\Traits\BillsTheUser;
use PHPUnit\Framework\MockObject\Stub;
use Tests\TestCase;

/** Starting checkout from a promoted price sends its code, so the buyer types nothing. */
class PromotionCheckoutTest extends TestCase
{
    use BillsTheUser;
    use RefreshDatabase;

    /** @var StripeGateway&Stub */
    private StripeGateway $gateway;

    private User $user;

    private Price $price;

    /** @var list<CheckoutData> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createStub(StripeGateway::class);
        $this->gateway->method('createCustomer')->willReturn('cus_promo');
        $this->gateway->method('createCheckoutSession')->willReturnCallback(function (CheckoutData $data): CheckoutResultData {
            $this->sent[] = $data;

            return new CheckoutResultData(sessionId: 'cs_promo', url: 'https://provider.test/cs_promo', provider: 'stripe');
        });

        $manager = $this->createStub(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->user = $this->createUser();
        $this->price = Price::factory()->yearly()->create(['currency' => 'USD']);
    }

    private function promote(array $attributes = []): Promotion
    {
        $promotion = Promotion::factory()->create(['code' => 'LAUNCH', 'max_redemptions' => 50, ...$attributes]);
        $this->price->update(['promotion_id' => $promotion->id]);

        return $promotion;
    }

    private function checkout(?string $coupon = null): CheckoutSession
    {
        $session = CheckoutSession::create([
            'price_id' => $this->price->id,
            'status' => CheckoutSessionStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        app(StartCheckout::class)->handle($session, $this->user, $this->user, 'https://app.test/ok', 'https://app.test/no', coupon: $coupon);

        return $session->fresh();
    }

    public function test_a_promoted_price_sends_its_code(): void
    {
        $this->promote();

        $session = $this->checkout();

        $this->assertSame('LAUNCH', $this->sent[0]->coupon);
        // Stored, so a retry or a replay under the same idempotency key sends it again.
        $this->assertSame('LAUNCH', $session->coupon);
    }

    /** The 51st buyer: the page showed the regular price, so checkout asks for it. */
    public function test_an_exhausted_promotion_is_not_sent(): void
    {
        $this->promote(['times_redeemed' => 50]);

        $this->checkout();

        $this->assertNull($this->sent[0]->coupon);
    }

    public function test_an_expired_promotion_is_not_sent(): void
    {
        $this->promote(['expires_at' => now()->subSecond()]);

        $this->checkout();

        $this->assertNull($this->sent[0]->coupon);
    }

    /** The page showed the regular price, and the provider would refuse the discount outright. */
    public function test_an_amount_off_in_another_currency_is_not_sent(): void
    {
        $this->price->update(['currency' => 'USD']);
        $this->promote(['amount_off' => 3000, 'currency' => 'EUR']);

        $this->checkout();

        $this->assertNull($this->sent[0]->coupon);
    }

    /** The provider takes one code; one the buyer typed is the one they meant. */
    public function test_a_typed_code_wins(): void
    {
        $this->promote();

        $this->checkout(coupon: 'FRIEND');

        $this->assertSame('FRIEND', $this->sent[0]->coupon);
    }

    public function test_the_pricing_button_goes_straight_to_the_provider_with_the_code(): void
    {
        $this->promote();
        app(BillingSettings::class)->fill(['redirect_to_gateway' => true])->save();

        $this->actingAs($this->user)->post(route('billing.checkout.create'), ['price_id' => $this->price->id]);

        $this->assertSame('LAUNCH', $this->sent[0]->coupon);
    }
}
