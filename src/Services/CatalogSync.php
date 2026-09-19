<?php

namespace Modules\Billing\Services;

use Modules\Billing\Data\CatalogProductData;
use Modules\Billing\Data\CatalogSyncReport;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;

/**
 * Pull the provider's products and prices into the app. The provider owns what
 * it charges; the app owns how it is shown, so only the former is ever written
 * to a row that already exists.
 */
class CatalogSync
{
    public function __construct(
        private PaymentGatewayManager $manager,
    ) {}

    public function run(): CatalogSyncReport
    {
        $provider = $this->manager->getDefaultDriver();
        $report = new CatalogSyncReport;

        $seen = [];
        // Counted up in memory: re-reading the max for every new product would be
        // a query per row on a first sync.
        $order = (int) Product::withTrashed()->max('display_order');

        foreach ($this->manager->driver($provider)->listCatalog() as $remote) {
            $product = $this->import($provider, $remote, $order);

            if (! $product) {
                $report->skipped++;

                continue;
            }

            if ($product->wasRecentlyCreated) {
                $order++;
                $report->created++;
            } else {
                $report->updated++;
            }

            array_push($seen, ...array_map(fn ($price) => $price->providerPriceId, $remote->prices));
        }

        // Not archived, not deleted: the provider has never heard of them, so it
        // has nothing to say about them. The report names them instead.
        $report->localOnly = Price::where('provider', $provider)
            ->whereNotNull('provider_price_id')
            ->whereNotIn('provider_price_id', $seen)
            ->pluck('provider_price_id')
            ->all();

        return $report;
    }

    /** @return Product|null Null when the product was archived before this app ever saw it. */
    private function import(string $provider, CatalogProductData $remote, int $order): ?Product
    {
        // Trashed rows included: deleting is the app's call, like hiding, and the
        // unique index would refuse a second row for the same provider product.
        $product = Product::withTrashed()->firstOrNew([
            'provider' => $provider,
            'provider_product_id' => $remote->providerProductId,
        ]);

        if (! $product->exists) {
            // A plan retired before this app existed is history, not catalogue.
            // One the app already knows still has to be deactivated below, since
            // subscriptions may point at it.
            if (! $remote->active) {
                return null;
            }

            // First sight only: the app owns its own words about a plan from
            // here on, and the push sends them back every run, so re-reading
            // them each sync would have the two overwrite each other. Hidden
            // until an admin has looked; the provider's product ID is as good a
            // SKU as any.
            $product->fill([
                'sku' => $remote->providerProductId,
                // Appended rather than left at 0, so an import never lands a
                // batch of products on the same rank for the admin to untangle.
                'display_order' => $order + 1,
                'description' => $remote->description,
                'features' => $remote->features,
                'is_visible' => false,
            ]);
        }

        $product->fill(['name' => $remote->name, 'is_active' => $remote->active])->save();

        foreach ($remote->prices as $price) {
            $product->prices()->updateOrCreate(
                ['provider' => $provider, 'provider_price_id' => $price->providerPriceId],
                [
                    'currency' => $price->currency,
                    'amount' => $price->amount,
                    'interval' => $price->interval,
                    'interval_count' => $price->intervalCount,
                    'is_active' => $price->active,
                ],
            );
        }

        return $product;
    }
}
