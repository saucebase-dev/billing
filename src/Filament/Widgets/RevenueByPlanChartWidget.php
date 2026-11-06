<?php

namespace Modules\Billing\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Modules\Billing\Support\BillingMetrics;
use Saucebase\Core\Filament\Concerns\ReadsPeriod;

/** Billing dashboard only: which plans the period's revenue came from. */
class RevenueByPlanChartWidget extends ChartWidget
{
    use ReadsPeriod;

    /** The other billing charts' colours, then three more; repeats past six plans. */
    private const COLORS = ['rgb(99, 102, 241)', 'rgb(16, 185, 129)', 'rgb(245, 158, 11)', 'rgb(236, 72, 153)', 'rgb(14, 165, 233)', 'rgb(168, 85, 247)'];

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    public static function canView(): bool
    {
        return auth()->user()?->can('manage billing') ?? false;
    }

    public function getHeading(): string
    {
        return __('Revenue by plan');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $plans = (new BillingMetrics)->revenueByPlan($this->period());

        return [
            'datasets' => [[
                'label' => __('Revenue'),
                'data' => array_map(fn (int $minor): float => round($minor / 100, 2), array_values($plans)),
                'backgroundColor' => array_map(fn (int $i): string => self::COLORS[$i % count(self::COLORS)], array_keys(array_values($plans))),
            ]],
            // A numeric plan name comes back as an int key.
            'labels' => array_map(fn (int|string $plan): string => $plan === '' ? __('Other') : (string) $plan, array_keys($plans)),
        ];
    }
}
