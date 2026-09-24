<?php

namespace Modules\Billing\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\On;
use Modules\Billing\Filament\Traits\GroupsByMonth;
use Modules\Billing\Models\Subscription;

class SubscriptionsChartWidget extends ChartWidget
{
    use GroupsByMonth;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    public string $startDate = '';

    public string $endDate = '';

    public function mount(): void
    {
        $this->startDate = now()->subDays(30)->startOfDay()->toDateTimeString();
        $this->endDate = now()->toDateTimeString();

        parent::mount();
    }

    public function getHeading(): string
    {
        return __('New Subscriptions Over Time');
    }

    public function getDescription(): string
    {
        return __('Monthly count of new subscriptions created');
    }

    protected function getType(): string
    {
        return 'line';
    }

    #[On('billing-filter-updated')]
    public function billingFilterUpdated(string $start, string $end): void
    {
        $this->startDate = $start;
        $this->endDate = $end;
        $this->cachedData = null;
        $this->updateChartData();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $buckets = $this->monthlyBuckets();

        try {
            $query = Subscription::query();

            $rows = $query->whereBetween('created_at', [$this->startDate, $this->endDate])
                ->selectRaw($this->monthOf($query).' as month, COUNT(*) as total')
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            foreach ($rows as $row) {
                $month = (string) $row->getAttribute('month');
                if (array_key_exists($month, $buckets)) {
                    $buckets[$month] = (int) $row->getAttribute('total');
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $labels = array_map(
            fn (string $month) => Carbon::parse($month.'-01')->format('M Y'),
            array_keys($buckets)
        );

        return [
            'datasets' => [
                [
                    'label' => __('New Subscriptions'),
                    'data' => array_values($buckets),
                    'borderColor' => 'rgb(16, 185, 129)',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'tension' => 0.3,
                    'fill' => false,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
