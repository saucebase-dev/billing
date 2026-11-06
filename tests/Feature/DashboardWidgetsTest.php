<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Modules\Billing\Filament\Pages\BillingDashboard;
use Modules\Billing\Filament\Widgets\BillingAttentionWidget;
use Modules\Billing\Filament\Widgets\BillingHealthWidget;
use Modules\Billing\Filament\Widgets\BillingSaasStatsWidget;
use Modules\Billing\Filament\Widgets\ConversionChartWidget;
use Modules\Billing\Filament\Widgets\RevenueByPlanChartWidget;
use Modules\Billing\Filament\Widgets\RevenueChartWidget;
use Modules\Billing\Filament\Widgets\SubscriptionsChartWidget;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;

/**
 * The cards and charts read the period from the dashboard's picker; the
 * numbers themselves are BillingMetricsTest's.
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = ['pageFilters' => ['period' => '7d']];

    protected function setUp(): void
    {
        parent::setUp();

        Exceptions::fake();
        Carbon::setTestNow('2026-11-06 15:00:00');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    public function test_the_billing_dashboard_has_the_period_picker_and_its_widgets(): void
    {
        Livewire::test(BillingDashboard::class)
            ->assertOk()
            ->assertActionExists('filter');

        $this->assertSame([
            BillingSaasStatsWidget::class,
            BillingHealthWidget::class,
            RevenueChartWidget::class,
            SubscriptionsChartWidget::class,
            ConversionChartWidget::class,
            RevenueByPlanChartWidget::class,
        ], (new BillingDashboard)->getWidgets());
    }

    public function test_the_headline_cards_compare_with_the_previous_period(): void
    {
        Product::factory()->create();
        Payment::factory()->create(['amount' => 3000, 'amount_refunded' => 0, 'created_at' => '2026-11-05']);
        Payment::factory()->create(['amount' => 1000, 'amount_refunded' => 0, 'created_at' => '2026-10-28']);

        Livewire::test(BillingSaasStatsWidget::class, self::WEEK)
            ->assertSee(['Revenue', '+200% vs previous period', 'MRR', 'New subscriptions']);
        Exceptions::assertNothingReported();
    }

    /** A new install has no plans: one pointer to set billing up, not a row of zeros. */
    public function test_without_plans_the_headline_says_how_to_start(): void
    {
        Livewire::test(BillingSaasStatsWidget::class, self::WEEK)
            ->assertSee('Set up billing')
            ->assertDontSee('MRR');
    }

    public function test_the_health_cards(): void
    {
        Subscription::factory()->create();

        Livewire::test(BillingHealthWidget::class, self::WEEK)
            ->assertSee(['Active subscriptions', 'Churn', 'ARPU', 'Checkout conversion', 'Refunds', 'Failed payments']);
        Exceptions::assertNothingReported();
    }

    public function test_charts_follow_the_period(): void
    {
        Payment::factory()->create(['amount' => 700, 'amount_refunded' => 0, 'created_at' => '2026-11-05']);

        foreach ([RevenueChartWidget::class, SubscriptionsChartWidget::class, ConversionChartWidget::class, RevenueByPlanChartWidget::class] as $chart) {
            Livewire::test($chart, self::WEEK)->assertOk();
        }

        $data = (fn () => $this->getData())->call(tap(new RevenueChartWidget, fn ($w) => $w->pageFilters = self::WEEK['pageFilters']));
        $this->assertCount(7, $data['labels']);
        $this->assertSame(7.0, $data['datasets'][0]['data'][5]); // shown in major units
        Exceptions::assertNothingReported();
    }

    /** Shown only when something needs an admin. */
    public function test_attention_shows_only_with_something_to_do(): void
    {
        $this->assertFalse(BillingAttentionWidget::canView());

        Subscription::factory()->pastDue()->create();
        Payment::factory()->failed()->create();

        $this->assertTrue(BillingAttentionWidget::canView());
        Livewire::test(BillingAttentionWidget::class)->assertSee(['Past due subscriptions', 'Failed payments']);
    }

    /** A zero isn't something to attend to. */
    public function test_attention_lists_only_what_needs_doing(): void
    {
        Subscription::factory()->pastDue()->create();

        Livewire::test(BillingAttentionWidget::class)
            ->assertSee('Past due subscriptions')
            ->assertDontSee('Failed payments');
    }
}
