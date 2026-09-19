<?php

namespace Modules\Billing\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\CatalogPush;
use Modules\Billing\Services\PaymentGatewayManager;

/** Create one product, and any of its prices the provider lacks, at the provider. */
class PushProductAction
{
    public static function make(): Action
    {
        $provider = ucfirst(app(PaymentGatewayManager::class)->getDefaultDriver());

        return Action::make('push')
            ->label(__('Push to :provider', ['provider' => $provider]))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('Creates this product and its new prices at the provider. Prices it already knows about are left alone.'))
            // Read off the loaded relation: as a row action this runs once per
            // row, and a query here would be one SELECT per product listed.
            ->hidden(fn (Product $record) => $record->provider_product_id !== null
                && ! $record->prices->contains(fn ($price) => $price->provider_price_id === null))
            ->action(function (Product $record, CatalogPush $push) use ($provider): void {
                $report = $push->run($record);

                Notification::make()
                    ->title(__(':products products, :prices prices created and :features feature lists sent to :provider', ['products' => $report->products, 'prices' => $report->prices, 'features' => $report->features, 'provider' => $provider]))
                    ->success()
                    ->send();
            })
            ->extraAttributes(['data-testid' => 'admin-product-push']);
    }
}
