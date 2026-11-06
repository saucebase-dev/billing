<?php

namespace Modules\Billing\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Modules\Billing\Support\BillingMetrics;
use Saucebase\Core\Filament\Concerns\ReadsPeriod;

/** Billing dashboard only. Buckets by day, week or month, following the period. */
class ConversionChartWidget extends ChartWidget
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
        return __('Checkout conversion (%)');
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
                'label' => __('Conversion'),
                'data' => (new BillingMetrics)->conversionSeries($period),
                'borderColor' => 'rgb(245, 158, 11)',
                'tension' => 0.3,
            ]],
            'labels' => array_values($period->labels()),
        ];
    }
}
