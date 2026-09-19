<?php

namespace Modules\Billing\Services;

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

        Product::whereNull('provider_product_id')
            ->when($only, fn ($query) => $query->whereKey($only))
            ->each(function (Product $product) use ($provider, $gateway, $report): void {
            $product->update([
                'provider' => $provider,
                'provider_product_id' => $gateway->createProduct($product),
            ]);
            $report->products++;
        });

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
}
