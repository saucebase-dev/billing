<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Support\BillingMetrics;
use Saucebase\Core\Filament\Period;
use Tests\TestCase;

/** The numbers behind every billing card, in the app's default currency. */
class BillingMetricsTest extends TestCase
{
    use RefreshDatabase;

    private BillingMetrics $metrics;

    private Period $week;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-06 15:00:00');
        $this->metrics = new BillingMetrics;
        $this->week = Period::fromFilters(['period' => '7d']); // Oct 31 – Nov 6
    }

    private function subscription(Price $price, string $createdAt, array $attributes = []): Subscription
    {
        $subscription = Subscription::factory()->create(['price_id' => $price->id, ...$attributes]);
        $subscription->forceFill(['created_at' => $createdAt])->save();

        return $subscription;
    }

    public function test_revenue_is_net_of_refunds_and_in_the_default_currency(): void
    {
        Payment::factory()->create(['amount' => 1000, 'created_at' => '2026-11-01']);
        Payment::factory()->partiallyRefunded()->create(['amount' => 1000, 'amount_refunded' => 500, 'created_at' => '2026-11-02']);
        Payment::factory()->refunded()->create(['amount' => 1000, 'amount_refunded' => 1000, 'created_at' => '2026-11-03']);
        Payment::factory()->failed()->create(['amount' => 1000, 'created_at' => '2026-11-03']);
        Payment::factory()->create(['amount' => 1000, 'currency' => 'BRL', 'created_at' => '2026-11-03']);
        Payment::factory()->create(['amount' => 1000, 'created_at' => '2026-10-01']); // outside

        $this->assertSame(1500, $this->metrics->revenue($this->week));
        $this->assertSame(1500, $this->metrics->refunds($this->week));
        $this->assertSame(1500, array_sum($this->metrics->revenueSeries($this->week)));
        $this->assertSame(1, $this->metrics->failedPayments($this->week));
    }

    public function test_mrr_turns_each_interval_into_a_monthly_amount(): void
    {
        $this->subscription(Price::factory()->create(['amount' => 1000, 'interval' => 'month']), '2026-01-01');
        $this->subscription(Price::factory()->create(['amount' => 12000, 'interval' => 'year']), '2026-01-01');
        $this->subscription(Price::factory()->create(['amount' => 3000, 'interval' => 'month', 'interval_count' => 3]), '2026-01-01');
        $this->subscription(Price::factory()->create(['amount' => 1000, 'interval' => 'month', 'currency' => 'BRL']), '2026-01-01');

        $this->assertSame(3000, $this->metrics->mrrAt(now()));
    }

    /** History is estimated from when each subscription started and ended. */
    public function test_mrr_and_active_subscriptions_at_a_past_date(): void
    {
        $price = Price::factory()->create(['amount' => 1000, 'interval' => 'month']);
        $this->subscription($price, '2026-01-01');
        $this->subscription($price, '2026-11-02');                                                   // started since
        $this->subscription($price, '2026-01-01', ['status' => 'cancelled', 'cancelled_at' => '2026-11-01', 'ends_at' => '2026-11-03']);
        $this->subscription($price, '2026-01-01', ['status' => 'pending']);

        $start = $this->week->start;

        $this->assertSame(2000, $this->metrics->mrrAt($start));
        $this->assertSame(2, $this->metrics->activeAt($start));
        $this->assertSame(2000, $this->metrics->mrrAt(now()));
        $this->assertSame(2, $this->metrics->activeAt(now()));
    }

    /** MRR is in the default currency, so the count it's divided by is too. */
    public function test_arpu_divides_by_subscriptions_in_the_same_currency(): void
    {
        $this->subscription(Price::factory()->create(['amount' => 1000, 'interval' => 'month']), '2026-01-01');
        $this->subscription(Price::factory()->create(['amount' => 1000, 'interval' => 'month', 'currency' => 'BRL']), '2026-01-01');

        $this->assertSame(1000, $this->metrics->arpuAt(now()));
        $this->assertSame(0, $this->metrics->arpuAt(Carbon::parse('2025-01-01')));
    }

    /** Suspension records when it happened (`grace_ends_at`): before that, the subscription counted. */
    public function test_a_suspended_subscription_counts_until_it_was_suspended(): void
    {
        $price = Price::factory()->create(['amount' => 1000, 'interval' => 'month']);
        $this->subscription($price, '2026-01-01', ['status' => 'suspended', 'grace_ends_at' => '2026-11-02 12:00:00']);

        $this->assertSame(1, $this->metrics->activeAt(Carbon::parse('2026-11-01')));
        $this->assertSame(1000, $this->metrics->mrrAt(Carbon::parse('2026-11-01')));
        $this->assertSame(0, $this->metrics->activeAt(now()));
    }

    public function test_churn_is_cancellations_over_subscriptions_active_at_the_start(): void
    {
        $price = Price::factory()->create(['interval' => 'month']);
        foreach (range(1, 3) as $i) {
            $this->subscription($price, '2026-01-01');
        }
        $this->subscription($price, '2026-01-01', ['status' => 'cancelled', 'cancelled_at' => '2026-11-01', 'ends_at' => '2026-12-01']);

        $this->assertSame(1, $this->metrics->cancelled($this->week));
        $this->assertSame(25.0, $this->metrics->churnRate($this->week));
        $this->assertNull($this->metrics->churnRate(Period::fromFilters(['period' => 'custom', 'start' => '2025-01-01', 'end' => '2025-01-07'])));
    }

    public function test_new_subscriptions_and_past_due(): void
    {
        $price = Price::factory()->create(['interval' => 'month']);
        $this->subscription($price, '2026-11-05');
        $this->subscription($price, '2026-11-05', ['status' => 'past_due']);
        $this->subscription($price, '2026-01-01');

        $this->assertSame(2, $this->metrics->newSubscriptions($this->week));
        $this->assertSame(2, array_sum($this->metrics->newSubscriptionsSeries($this->week)));
        $this->assertSame(1, $this->metrics->pastDue());
    }

    public function test_conversion_counts_finished_checkouts_only(): void
    {
        $price = Price::factory()->create();
        foreach ([CheckoutSessionStatus::Completed, CheckoutSessionStatus::Abandoned, CheckoutSessionStatus::Expired, CheckoutSessionStatus::Pending] as $status) {
            CheckoutSession::create(['price_id' => $price->id, 'status' => $status, 'expires_at' => now()])
                ->forceFill(['created_at' => '2026-11-04'])->save();
        }

        $this->assertSame(33.3, $this->metrics->conversionRate($this->week));
        $this->assertNull($this->metrics->conversionRate($this->week->previous()));
        $this->assertSame([0, 0, 0, 0, 33.3, 0, 0], $this->metrics->conversionSeries($this->week));
    }

    public function test_revenue_by_plan(): void
    {
        $price = Price::factory()->create();
        Payment::factory()->create(['amount' => 700, 'price_id' => $price->id, 'created_at' => '2026-11-01']);
        Payment::factory()->create(['amount' => 300, 'created_at' => '2026-11-01']);

        // No translated text in the cache: the widget names the '' bucket in the reader's language.
        $this->assertSame([$price->product->name => 700, '' => 300], $this->metrics->revenueByPlan($this->week));
    }

    /** Two cards asking the same thing cost one query. */
    public function test_the_same_figure_is_worked_out_once(): void
    {
        $this->metrics->mrrAt($this->week->start);

        DB::enableQueryLog();
        $this->metrics->mrrAt($this->week->start);
        Cache::flush(); // the instance remembers, not only the cache
        $this->metrics->mrrAt($this->week->start);

        $this->assertCount(0, DB::getQueryLog());
    }

    /** Every widget is its own request: the figures are cached a few minutes, so the next one reads them. */
    public function test_figures_are_cached_for_a_few_minutes(): void
    {
        Payment::factory()->create(['amount' => 1000, 'amount_refunded' => 0, 'created_at' => '2026-11-05']);
        $first = (new BillingMetrics)->revenue($this->week);

        Payment::factory()->create(['amount' => 500, 'amount_refunded' => 0, 'created_at' => '2026-11-05']);
        $cached = (new BillingMetrics)->revenue($this->week);

        $this->travel(11)->minutes();
        $expired = (new BillingMetrics)->revenue($this->week);

        $this->assertSame([1000, 1000, 1500], [$first, $cached, $expired]);
    }
}
