<?php

namespace Modules\Billing\Services;

use Modules\Billing\Data\CatalogPriceData;
use Modules\Billing\Data\CatalogProductData;
use Modules\Billing\Data\CatalogPushReport;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Promotion;

/**
 * Create at the provider what was drafted here first. Only rows with no
 * provider ID are touched: the provider's prices are immutable, so anything it
 * already knows about is changed there and pulled, never pushed.
 */
class CatalogPush
{
    public function __construct(
        private PaymentGatewayManager $manager,
    ) {}

    /** @param  Product|null  $only  Push just this product and its prices. */
    public function run(?Product $only = null): CatalogPushReport
    {
        $provider = $this->manager->getDefaultDriver();
        $gateway = $this->manager->driver($provider);
        $report = new CatalogPushReport;

        $unpushed = Product::whereNull('provider_product_id')
            ->when($only, fn ($query) => $query->whereKey($only))
            ->get();

        // A reset database pushes the same plans again: reconnect to the ones
        // pushed last time, tagged with their slug, rather than copying them.
        $remoteBySlug = $unpushed->isEmpty() ? collect() : collect($gateway->listCatalog())
            ->filter(fn (CatalogProductData $remote) => $remote->active && $remote->slug !== null)
            ->keyBy('slug');

        foreach ($unpushed as $product) {
            $remote = $remoteBySlug->get($product->slug);

            if ($remote) {
                $this->reconnectPrices($product, $remote, $provider);
            }

            $product->update([
                'provider' => $provider,
                'provider_product_id' => $remote->providerProductId ?? $gateway->createProduct($product),
            ]);
            $report->products++;
        }

        // Features are the app's words, never the provider's, so they are sent
        // every run rather than only when the product is first created.
        Product::whereNotNull('provider_product_id')
            ->where('provider', $provider)
            ->when($only, fn ($query) => $query->whereKey($only))
            ->each(function (Product $product) use ($gateway, $report): void {
                $gateway->pushProductFeatures($product);
                $report->features++;
            });

        Price::whereNull('provider_price_id')
            // Scoped to this provider like the loops above: another provider's
            // product ID would be meaningless to this gateway.
            ->whereHas('product', fn ($query) => $query->whereNotNull('provider_product_id')->where('provider', $provider))
            ->when($only, fn ($query) => $query->where('product_id', $only->id))
            ->with('product')
            ->each(function (Price $price) use ($provider, $gateway, $report): void {
                $price->update([
                    'provider' => $provider,
                    'provider_price_id' => $gateway->createPrice($price, $price->product->provider_product_id),
                ]);
                $report->prices++;
            });

        // After the prices: a coupon is limited to its prices' products.
        $this->pushPromotions($provider, $report, $only);

        return $report;
    }

    /**
     * Create at the provider the promotions drafted here. One whose code the
     * provider already has (pushed before a reset) is adopted, with its count.
     */
    private function pushPromotions(string $provider, CatalogPushReport $report, ?Product $only): void
    {
        $gateway = $this->manager->driver($provider);
        $unpushed = Promotion::whereNull('provider_promotion_code_id')
            ->when($only, fn ($query) => $query->whereHas('prices', fn ($prices) => $prices->where('product_id', $only->id)))
            ->get();

        foreach ($unpushed as $promotion) {
            $existing = $gateway->findPromotion($promotion->code);

            // Codes are shared by everything on the account: one that makes
            // another offer is not this promotion, and adopting it would show
            // terms nobody wrote here. The code cannot be created twice either.
            if ($existing && ! $promotion->sameTermsAs($existing)) {
                $report->promotionWarnings[] = __(':code already exists at the provider with other terms; rename it here or change it there.', ['code' => $promotion->code]);

                continue;
            }

            if ($existing) {
                $promotion->fillFromProvider($existing);
            }

            $promotion->fill([
                'provider' => $provider,
                'provider_promotion_code_id' => $existing->providerPromotionCodeId ?? $gateway->createPromotion($promotion),
            ])->save();
            $report->promotions++;

            if ($warning = $promotion->untouchedPricesWarning()) {
                $report->promotionWarnings[] = $warning;
            }
        }
    }

    /** Adopt the provider's active prices that match one here; the rest are created below. */
    private function reconnectPrices(Product $product, CatalogProductData $remote, string $provider): void
    {
        $available = collect($remote->prices)->filter(fn (CatalogPriceData $price) => $price->active);

        foreach ($product->prices()->whereNull('provider_price_id')->get() as $price) {
            $key = $available->search(fn (CatalogPriceData $remotePrice) => $remotePrice->currency === $price->currency->value
                && $remotePrice->amount === $price->amount
                && $remotePrice->interval === $price->interval
                && ($remotePrice->intervalCount ?? 1) === ($price->interval_count ?? 1));

            if ($key !== false) {
                $price->update(['provider' => $provider, 'provider_price_id' => $available->pull($key)->providerPriceId]);
            }
        }
    }
}
