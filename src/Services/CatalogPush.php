<?php

namespace Modules\Billing\Services;

use Modules\Billing\Data\CatalogPriceData;
use Modules\Billing\Data\CatalogProductData;
use Modules\Billing\Data\CatalogPushReport;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;

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

        return $report;
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
