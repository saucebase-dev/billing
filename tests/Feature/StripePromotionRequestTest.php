<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Promotion;
use Modules\Billing\Services\Gateways\StripeGateway;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Tests\TestCase;

/** How a promotion is read from Stripe, and what checkout sends when Stripe no longer honours it. */
class StripePromotionRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    private array $requests = [];

    /** @param  array<string, string>  $bodies  One body per URL path. */
    private function gateway(array $bodies): StripeGateway
    {
        $record = function (array $request): void {
            $this->requests[] = $request;
        };

        ApiRequestor::setHttpClient(new class($record, $bodies) implements ClientInterface
        {
            public function __construct(private \Closure $record, private array $bodies) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $path = parse_url($absUrl, PHP_URL_PATH);
                ($this->record)(['method' => $method, 'path' => $path, 'params' => $params]);

                return [$this->bodies[$path], 200, []];
            }
        });

        return new StripeGateway(new StripeClient(['api_key' => 'sk_test_stub', 'max_network_retries' => 0]));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function code(array $code = [], array $coupon = []): array
    {
        return [
            'id' => 'promo_launch',
            'object' => 'promotion_code',
            'code' => 'LAUNCH',
            'active' => true,
            'max_redemptions' => 50,
            'times_redeemed' => 12,
            'expires_at' => null,
            ...$code,
            'promotion' => ['type' => 'coupon', 'coupon' => [
                'id' => 'co_launch',
                'object' => 'coupon',
                'amount_off' => 3000,
                'currency' => 'usd',
                'percent_off' => null,
                'duration' => 'once',
                'duration_in_months' => null,
                'max_redemptions' => null,
                'redeem_by' => null,
                'valid' => true,
                ...$coupon,
            ]],
        ];
    }

    private function list(array ...$codes): string
    {
        return json_encode(['object' => 'list', 'data' => $codes, 'has_more' => false, 'url' => '/v1/promotion_codes']);
    }

    public function test_a_code_is_found_with_its_coupons_terms(): void
    {
        $promotion = $this->gateway(['/v1/promotion_codes' => $this->list($this->code())])->findPromotion('LAUNCH');

        $this->assertSame('LAUNCH', $this->requests[0]['params']['code']);
        $this->assertSame(['data.promotion.coupon'], $this->requests[0]['params']['expand']);
        $this->assertSame('promo_launch', $promotion->providerPromotionCodeId);
        $this->assertSame(3000, $promotion->amountOff);
        $this->assertSame('USD', $promotion->currency);
        $this->assertNull($promotion->percentOff);
        $this->assertSame('once', $promotion->duration);
        $this->assertSame(50, $promotion->maxRedemptions);
        $this->assertSame(12, $promotion->timesRedeemed);
        $this->assertTrue($promotion->active);
    }

    public function test_an_unknown_code_is_null(): void
    {
        $this->assertNull($this->gateway(['/v1/promotion_codes' => $this->list()])->findPromotion('NOPE'));
    }

    /** A cap or end date set on the coupon binds every code on it; the tighter one is the one shown. */
    public function test_the_coupons_cap_and_end_bind_when_tighter(): void
    {
        $end = now()->addWeek()->startOfSecond();

        $promotion = $this->gateway(['/v1/promotion_codes/promo_launch' => json_encode($this->code(
            ['max_redemptions' => null, 'expires_at' => $end->copy()->addWeek()->timestamp],
            ['max_redemptions' => 30, 'redeem_by' => $end->timestamp, 'percent_off' => 20.5, 'amount_off' => null, 'currency' => null, 'duration' => 'repeating', 'duration_in_months' => 3],
        ))])->retrievePromotion('promo_launch');

        $this->assertSame(30, $promotion->maxRedemptions);
        $this->assertTrue($end->equalTo($promotion->expiresAt));
        $this->assertSame(20.5, $promotion->percentOff);
        $this->assertSame('repeating', $promotion->duration);
        $this->assertSame(3, $promotion->durationInMonths);
    }

    /** Codes sharing a coupon share its cap: 49 used through other codes leaves one, whatever this code counted. */
    public function test_a_shared_coupon_cap_counts_every_codes_redemptions(): void
    {
        $promotion = $this->gateway(['/v1/promotion_codes/promo_launch' => json_encode($this->code(
            ['max_redemptions' => 100, 'times_redeemed' => 0],
            ['max_redemptions' => 50, 'times_redeemed' => 49],
        ))])->retrievePromotion('promo_launch');

        $this->assertSame(1, $promotion->maxRedemptions - $promotion->timesRedeemed);
    }

    public function test_the_codes_own_cap_binds_when_it_leaves_fewer(): void
    {
        $promotion = $this->gateway(['/v1/promotion_codes/promo_launch' => json_encode($this->code(
            ['max_redemptions' => 50, 'times_redeemed' => 48],
            ['max_redemptions' => 1000, 'times_redeemed' => 300],
        ))])->retrievePromotion('promo_launch');

        $this->assertSame(50, $promotion->maxRedemptions);
        $this->assertSame(48, $promotion->timesRedeemed);
    }

    public function test_a_coupon_no_longer_valid_makes_the_promotion_inactive(): void
    {
        $promotion = $this->gateway(['/v1/promotion_codes/promo_launch' => json_encode($this->code(coupon: ['valid' => false]))])
            ->retrievePromotion('promo_launch');

        $this->assertFalse($promotion->active);
    }

    private function checkout(array $codes): array
    {
        $gateway = $this->gateway([
            '/v1/promotion_codes' => $this->list(...$codes),
            '/v1/checkout/sessions' => json_encode(['id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.test/cs_test']),
        ]);

        $gateway->createCheckoutSession(new CheckoutData(
            customer: Customer::factory()->create(['provider_customer_id' => 'cus_123']),
            price: Price::factory()->yearly()->create(),
            successUrl: 'https://app.test/ok',
            cancelUrl: 'https://app.test/no',
            coupon: 'LAUNCH',
        ));

        return collect($this->requests)->firstWhere('path', '/v1/checkout/sessions')['params'];
    }

    public function test_a_code_that_holds_is_applied(): void
    {
        $sent = $this->checkout([$this->code()]);

        $this->assertSame([['promotion_code' => 'promo_launch']], $sent['discounts']);
        $this->assertArrayNotHasKey('custom_text', $sent);
    }

    /** Counted here at 49 while Stripe is at 50: Stripe wins, and the buyer reads why before paying. */
    public function test_a_code_stripe_has_used_up_checks_out_at_the_regular_price_and_says_so(): void
    {
        $sent = $this->checkout([$this->code(['times_redeemed' => 50])]);

        $this->assertArrayNotHasKey('discounts', $sent);
        $this->assertSame('true', $sent['allow_promotion_codes']);
        $this->assertStringContainsString('LAUNCH', $sent['custom_text']['submit']['message']);
    }

    /**
     * Stripe refuses the whole session when a pre-applied code's restrictions rule the buyer
     * out (first-time customers, a minimum amount, one customer). Such a code is never
     * pre-applied: the buyer types it on Stripe's page, which checks whether it applies.
     */
    public function test_a_restricted_code_is_left_for_the_buyer_to_type_on_stripes_page(): void
    {
        foreach ([
            ['first_time_transaction' => true, 'minimum_amount' => null, 'minimum_amount_currency' => null],
            ['first_time_transaction' => false, 'minimum_amount' => 5000, 'minimum_amount_currency' => 'usd'],
        ] as $restrictions) {
            $this->requests = [];
            $sent = $this->checkout([$this->code(['restrictions' => $restrictions])]);

            $this->assertArrayNotHasKey('discounts', $sent);
            $this->assertSame('true', $sent['allow_promotion_codes']);
            $this->assertStringContainsString('LAUNCH', $sent['custom_text']['submit']['message']);
            $this->assertStringNotContainsString('no longer available', $sent['custom_text']['submit']['message']);
        }

        $this->requests = [];
        $sent = $this->checkout([$this->code(['customer' => 'cus_someone_else'])]);
        $this->assertArrayNotHasKey('discounts', $sent);
    }

    public function test_a_code_stripe_no_longer_has_says_so_too(): void
    {
        $sent = $this->checkout([]);

        $this->assertArrayNotHasKey('discounts', $sent);
        $this->assertStringContainsString('LAUNCH', $sent['custom_text']['submit']['message']);
    }

    public function test_a_promotion_is_created_as_a_coupon_and_its_code(): void
    {
        $promotion = Promotion::factory()->create([
            'provider_promotion_code_id' => null, 'code' => 'LAUNCH', 'amount_off' => 1000, 'currency' => 'USD',
            'duration' => 'once', 'max_redemptions' => 50, 'expires_at' => now()->addMonth(),
        ]);
        $price = Price::factory()->create(['promotion_id' => $promotion->id]);
        $price->product->update(['provider_product_id' => 'prod_pro']);

        $id = $this->gateway([
            '/v1/coupons' => json_encode(['id' => 'co_new', 'object' => 'coupon']),
            '/v1/promotion_codes' => json_encode(['id' => 'promo_new', 'object' => 'promotion_code']),
        ])->createPromotion($promotion);

        $this->assertSame('promo_new', $id);
        [$coupon, $code] = $this->requests;
        $this->assertSame('/v1/coupons', $coupon['path']);
        $this->assertSame(1000, $coupon['params']['amount_off']);
        $this->assertSame('usd', $coupon['params']['currency']);
        $this->assertSame('once', $coupon['params']['duration']);
        $this->assertSame(['prod_pro'], $coupon['params']['applies_to']['products']);
        $this->assertSame('LAUNCH', $code['params']['code']);
        $this->assertSame(['type' => 'coupon', 'coupon' => 'co_new'], $code['params']['promotion']);
        $this->assertSame(50, $code['params']['max_redemptions']);
        $this->assertSame($promotion->expires_at->timestamp, $code['params']['expires_at']);
    }
}
