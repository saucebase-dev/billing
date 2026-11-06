<?php

namespace Modules\Billing\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Support\BillingMetrics;
use Saucebase\Core\Filament\Concerns\ReadsPeriod;
use Saucebase\Core\Filament\TrendStat;

/** The billing dashboard's second row: how healthy the subscriber base is. */
class BillingHealthWidget extends StatsOverviewWidget
{
    use ReadsPeriod;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->can('manage billing') ?? false;
    }

    protected function getStats(): array
    {
        $metrics = new BillingMetrics;
        $period = $this->period();
        $previous = $period->previous();
        $money = fn (int|float $amount): string => Currency::default()->formatAmount((int) $amount);
        $percent = fn (int|float $rate): string => $rate.'%';

        $active = $metrics->activeAt($period->end);
        $activeBefore = $metrics->activeAt($period->start);

        return [
            TrendStat::make(__('Active subscriptions'), $active, $activeBefore),
            $this->rate(__('Churn'), $metrics->churnRate($period), $metrics->churnRate($previous), $percent, lowerIsBetter: true),
            TrendStat::make(__('ARPU'), $metrics->arpuAt($period->end), $metrics->arpuAt($period->start), format: $money),
            $this->rate(__('Checkout conversion'), $metrics->conversionRate($period), $metrics->conversionRate($previous), $percent),
            TrendStat::make(__('Refunds'), $metrics->refunds($period), $metrics->refunds($previous), lowerIsBetter: true, format: $money),
            TrendStat::make(__('Failed payments'), $metrics->failedPayments($period), $metrics->failedPayments($previous), lowerIsBetter: true),
        ];
    }

    /** A rate with nothing to divide by is a dash, not 0%. */
    private function rate(string $label, ?float $now, ?float $before, \Closure $format, bool $lowerIsBetter = false): Stat
    {
        return $now === null
            ? Stat::make($label, '—')->description(__('Not enough data in this period'))->color('gray')
            : TrendStat::make($label, $now, $before ?? 0, lowerIsBetter: $lowerIsBetter, format: $format);
    }
}
