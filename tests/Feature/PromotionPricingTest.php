<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Promotion;
use Tests\TestCase;

/** A promotion is shown from stored data only, and ends on its own when spent or past its date. */
class PromotionPricingTest extends TestCase
{
    use RefreshDatabase;

    private Price $yearly;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::factory()->create(['is_active' => true, 'is_visible' => true]);
        $this->yearly = Price::factory()->yearly()->create(['product_id' => $product->id, 'amount' => 17900, 'currency' => 'USD']);
    }

    private function promote(array $attributes = []): Promotion
    {
        $promotion = Promotion::factory()->create(['amount_off' => 3000, 'currency' => 'USD', 'max_redemptions' => 50, ...$attributes]);
        $this->yearly->update(['promotion_id' => $promotion->id]);

        return $promotion;
    }

    private function pricing(): AssertableInertia
    {
        $page = null;
        $this->get(route('billing.plans'))->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$page) {
            $page = $inertia;
        });

        return $page;
    }

    public function test_an_active_promotion_shows_its_price_and_what_is_left(): void
    {
        $this->promote(['code' => 'LAUNCH', 'times_redeemed' => 12, 'duration' => 'once']);

        $this->pricing()
            ->where('products.0.prices.0.amount', 17900)
            ->where('products.0.prices.0.promotional_amount', 14900)
            ->where('products.0.prices.0.promotion.code', 'LAUNCH')
            ->where('products.0.prices.0.promotion.remaining', 38)
            ->where('products.0.prices.0.promotion.max_redemptions', 50)
            ->where('products.0.prices.0.promotion.duration', 'once')
            ->missing('products.0.prices.0.promotion.provider_promotion_code_id');
    }

    public function test_a_percentage_promotion_takes_its_share_off(): void
    {
        $this->promote(['amount_off' => null, 'currency' => null, 'percent_off' => 25]);

        $this->pricing()->where('products.0.prices.0.promotional_amount', 13425);
    }

    public function test_an_exhausted_promotion_shows_the_regular_price(): void
    {
        $this->promote(['times_redeemed' => 50]);

        $this->pricing()
            ->where('products.0.prices.0.promotion', null)
            ->where('products.0.prices.0.promotional_amount', null);
    }

    public function test_an_expired_promotion_shows_the_regular_price(): void
    {
        $this->promote(['expires_at' => now()->subMinute()]);

        $this->pricing()->where('products.0.prices.0.promotion', null);
    }

    public function test_a_promotion_the_provider_deactivated_shows_the_regular_price(): void
    {
        $this->promote(['is_active' => false]);

        $this->pricing()->where('products.0.prices.0.promotion', null);
    }

    public function test_a_promotion_on_another_price_does_not_touch_this_one(): void
    {
        $this->promote();
        $monthly = Price::factory()->create(['product_id' => $this->yearly->product_id, 'amount' => 1900, 'currency' => 'USD']);

        $this->pricing()
            ->where('products.0.prices.1.id', $monthly->id)
            ->where('products.0.prices.1.promotion', null)
            ->where('products.0.prices.1.promotional_amount', null);
    }

    /** An amount off in another currency is not a discount on this price. */
    public function test_an_amount_off_in_another_currency_is_not_shown(): void
    {
        $this->promote(['currency' => 'EUR']);

        $this->pricing()->where('products.0.prices.0.promotional_amount', null);
    }

    public function test_an_open_ended_promotion_has_no_count(): void
    {
        $this->promote(['max_redemptions' => null, 'times_redeemed' => 400, 'expires_at' => now()->addWeek()]);

        $this->pricing()
            ->where('products.0.prices.0.promotion.remaining', null)
            ->where('products.0.prices.0.promotional_amount', 14900);
    }
}
