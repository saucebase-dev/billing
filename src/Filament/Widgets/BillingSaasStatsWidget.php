<?php

namespace Modules\Billing\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Filament\Resources\Products\ProductResource;
use Modules\Billing\Models\Product;
use Modules\Billing\Support\BillingMetrics;
use Saucebase\Core\Filament\Concerns\ReadsPeriod;
use Saucebase\Core\Filament\TrendStat;

/** Revenue, MRR and new subscriptions: on both the main dashboard (stats band, 12, full width) and billing's. */
class BillingSaasStatsWidget extends StatsOverviewWidget
{
    use ReadsPeriod;

    protected static ?int $sort = 12;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('manage billing') ?? false;
    }

    protected function getStats(): array
    {
        if (! Product::exists()) {
            return [
                Stat::make(__('Billing'), __('Set up billing'))
                    ->description(__('Add your first plan to start selling'))
                    ->descriptionIcon('heroicon-m-arrow-right')
                    ->url(ProductResource::getUrl('create')),
            ];
        }

        $metrics = new BillingMetrics;
        $period = $this->period();
        $previous = $period->previous();
        $money = fn (int|float $amount): string => Currency::default()->formatAmount((int) $amount);

        return [
            TrendStat::make(__('Revenue'), $metrics->revenue($period), $metrics->revenue($previous), $metrics->revenueSeries($period), format: $money),
            TrendStat::make(__('MRR'), $metrics->mrrAt($period->end), $metrics->mrrAt($period->start), format: $money),
            TrendStat::make(__('New subscriptions'), $metrics->newSubscriptions($period), $metrics->newSubscriptions($previous), $metrics->newSubscriptionsSeries($period)),
        ];
    }
}
