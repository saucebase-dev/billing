<?php

namespace Modules\Billing\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Modules\Billing\Support\BillingMetrics;
use Saucebase\Core\Filament\Concerns\ReadsPeriod;

/** Billing dashboard only. Buckets by day, week or month, following the period. */
class RevenueChartWidget extends ChartWidget
{
    use ReadsPeriod;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        return auth()->user()?->can('manage billing') ?? false;
    }

    public function getHeading(): string
    {
        return __('Revenue');
    }

    protected function getType(): string
    {
        return 'line';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $period = $this->period();

        return [
            'datasets' => [[
                'label' => __('Revenue'),
                'data' => array_map(fn (int|float $minor): float => round($minor / 100, 2), (new BillingMetrics)->revenueSeries($period)),
                'borderColor' => 'rgb(99, 102, 241)',
                'tension' => 0.3,
            ]],
            'labels' => array_values($period->labels()),
        ];
    }
}
