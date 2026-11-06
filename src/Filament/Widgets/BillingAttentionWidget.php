<?php

namespace Modules\Billing\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Filament\Resources\Subscriptions\SubscriptionResource;
use Modules\Billing\Models\Payment;
use Modules\Billing\Support\BillingMetrics;

/** Main dashboard, attention band (20): only while a subscription is past due or a payment failed this week. */
class BillingAttentionWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 20;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /** Half the dashboard: pairs with its neighbour in the band. */
    protected int|string|array $columnSpan = 1;

    protected function getColumns(): int
    {
        return count($this->getCachedStats());
    }

    public static function canView(): bool
    {
        return (auth()->user()?->can('manage billing') ?? false)
            && ((new BillingMetrics)->pastDue() > 0 || self::recentlyFailed() > 0);
    }

    protected function getStats(): array
    {
        $pastDue = (new BillingMetrics)->pastDue();
        $failed = self::recentlyFailed();

        return array_values(array_filter([
            $pastDue > 0 ? Stat::make(__('Past due subscriptions'), $pastDue)
                ->description(__('Waiting on a payment'))
                ->color('warning')
                ->url(SubscriptionResource::getUrl('index')) : null,
            $failed > 0 ? Stat::make(__('Failed payments'), $failed)
                ->description(__('Last 7 days'))
                ->color('danger') : null,
        ]));
    }

    private static function recentlyFailed(): int
    {
        return Payment::where('status', PaymentStatus::Failed)->where('created_at', '>=', now()->subDays(7))->count();
    }
}
