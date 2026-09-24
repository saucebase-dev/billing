<?php

namespace Modules\Billing\Filament\Resources\Products\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Modules\Billing\Exceptions\GatewayOperationFailed;
use Modules\Billing\Filament\Resources\Products\ProductResource;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\CatalogPush;
use Modules\Billing\Services\CatalogSync;
use Modules\Billing\Services\PaymentGatewayManager;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        $provider = app(PaymentGatewayManager::class)->getDefaultDriver();

        return [
            Action::make('sync')
                ->label(__('Sync from :provider', ['provider' => ucfirst($provider)]))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Pulls every product and price from the provider. New ones arrive hidden; your descriptions, features and visibility are kept.'))
                ->action(function (CatalogSync $sync): void {
                    // The provider is a network call in the middle of a Livewire
                    // action; a rate limit or a blip should read as a failed sync,
                    // not a 500.
                    try {
                        $report = $sync->run();
                    } catch (\Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title(__('Sync failed'))
                            ->body(self::failureMessage($e))
                            ->danger()
                            ->send();

                        return;
                    }

                    $notification = Notification::make()
                        ->title(__(':created created, :updated updated, :skipped archived and skipped', ['created' => $report->created, 'updated' => $report->updated, 'skipped' => $report->skipped]))
                        ->success();

                    if ($report->localOnly !== []) {
                        $notification->body(__('Not at the provider, left as they are: :ids', ['ids' => implode(', ', $report->localOnly)]));
                    }

                    $notification->send();
                })
                ->extraAttributes(['data-testid' => 'admin-products-sync']),
            Action::make('push')
                ->label(__('Push new to :provider', ['provider' => ucfirst($provider)]))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Creates at the provider every product and price that has no provider ID yet. Anything the provider already knows about is left alone: change those there and sync.'))
                ->action(function (CatalogPush $push) use ($provider): void {
                    // Several provider calls in a row: one failure part-way leaves
                    // what already succeeded written back, so say so rather than
                    // throwing away the report.
                    try {
                        $report = $push->run();
                    } catch (\Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title(__('Push failed'))
                            ->body(self::failureMessage($e))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__(':products products, :prices prices created and :features feature lists sent to :provider', ['products' => $report->products, 'prices' => $report->prices, 'features' => $report->features, 'provider' => ucfirst($provider)]))
                        ->success()
                        ->send();
                })
                ->hidden(fn () => ! Product::whereNull('provider_product_id')->exists()
                    && ! Price::whereNull('provider_price_id')->exists())
                ->extraAttributes(['data-testid' => 'admin-products-push']),
            CreateAction::make(),
        ];
    }

    /**
     * Ours to show; a provider's raw text or an internal error's is not, even
     * to an admin. The report has the details.
     */
    private static function failureMessage(\Throwable $e): string
    {
        return $e instanceof GatewayOperationFailed
            ? $e->getMessage()
            : __('Something went wrong. The error has been reported.');
    }
}
