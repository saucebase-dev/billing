<?php

namespace Modules\Billing\Tests\Feature;

use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Filament\Widgets\BillingSaasStatsWidget;
use Modules\Billing\Filament\Widgets\ConversionChartWidget;
use Modules\Billing\Filament\Widgets\RevenueChartWidget;
use Modules\Billing\Filament\Widgets\SubscriptionsChartWidget;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;

/**
 * The admin dashboard's numbers, on whichever database the app runs: the
 * queries group by month and count matches in SQL, which each engine spells
 * differently. A widget that fails reports it and shows an empty chart, so
 * the tests also check nothing was reported.
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Exceptions::fake();
        $this->travelTo('2026-03-15 12:00:00');
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $widget
     * @return T
     */
    private function widget(string $widget): object
    {
        return tap(new $widget, function (object $widget): void {
            $widget->startDate = '2026-01-01 00:00:00';
            $widget->endDate = '2026-03-31 23:59:59';
        });
    }

    /** @return list<int|float> */
    private function chart(object $widget): array
    {
        return (fn () => $this->getData())->call($widget)['datasets'][0]['data'];
    }

    private function checkout(CheckoutSessionStatus $status, string $at): void
    {
        CheckoutSession::create(['price_id' => Price::factory()->create()->id, 'status' => $status, 'expires_at' => now()])
            ->forceFill(['created_at' => $at])->save();
    }

    public function test_revenue_is_summed_per_month(): void
    {
        Payment::factory()->create(['status' => PaymentStatus::Succeeded, 'amount' => 1000, 'created_at' => '2026-01-10']);
        Payment::factory()->create(['status' => PaymentStatus::Succeeded, 'amount' => 500, 'created_at' => '2026-01-20']);
        Payment::factory()->create(['status' => PaymentStatus::Succeeded, 'amount' => 700, 'created_at' => '2026-03-02']);
        Payment::factory()->create(['status' => PaymentStatus::Failed, 'amount' => 9999, 'created_at' => '2026-03-03']);

        $this->assertSame([1500, 0, 700], $this->chart($this->widget(RevenueChartWidget::class)));
        Exceptions::assertNothingReported();
    }

    public function test_new_subscriptions_are_counted_per_month(): void
    {
        Subscription::factory()->create(['created_at' => '2026-02-01']);
        Subscription::factory()->create(['created_at' => '2026-02-28']);
        Subscription::factory()->create(['created_at' => '2026-03-01']);

        $this->assertSame([0, 2, 1], $this->chart($this->widget(SubscriptionsChartWidget::class)));
        Exceptions::assertNothingReported();
    }

    public function test_checkout_conversion_is_worked_out_per_month(): void
    {
        $this->checkout(CheckoutSessionStatus::Completed, '2026-01-05');
        $this->checkout(CheckoutSessionStatus::Abandoned, '2026-01-06');
        $this->checkout(CheckoutSessionStatus::Completed, '2026-03-07');
        $this->checkout(CheckoutSessionStatus::Pending, '2026-03-08');

        $this->assertSame([50.0, 0.0, 100.0], $this->chart($this->widget(ConversionChartWidget::class)));
        Exceptions::assertNothingReported();
    }

    public function test_the_overall_conversion_rate_counts_completed_checkouts(): void
    {
        $this->checkout(CheckoutSessionStatus::Completed, '2026-01-05');
        $this->checkout(CheckoutSessionStatus::Expired, '2026-02-06');
        $this->checkout(CheckoutSessionStatus::Abandoned, '2026-03-06');
        $this->checkout(CheckoutSessionStatus::Completed, '2026-03-07');

        /** @var list<Stat> $stats */
        $stats = (fn () => $this->getStats())->call($this->widget(BillingSaasStatsWidget::class));

        $this->assertSame('50%', $stats[3]->getValue());
    }
}
