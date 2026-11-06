<?php

namespace Modules\Billing\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;
use Saucebase\Core\Filament\Period;

/**
 * The numbers behind the billing cards, in the default currency only: amounts
 * in different currencies don't add up.
 *
 * Subscriptions keep no history, so MRR and active counts at a past date are
 * estimated from when each one started and ended, at its current price.
 */
final class BillingMetrics
{
    /** @var array<string, mixed> this instance's answers, so one widget asking twice queries once */
    private array $answered = [];

    private ?Currency $currency = null;

    /** Net of refunds, from payments made in the period. */
    public function revenue(Period $period): int
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            return (int) $this->payments($period)->sum(DB::raw($this->net()));
        });
    }

    /** @return list<int|float> */
    public function revenueSeries(Period $period): array
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            return $period->series($this->paidPayments(), "sum({$this->net()})");
        });
    }

    /** Refunded so far on payments made in the period (refunds keep no date of their own). */
    public function refunds(Period $period): int
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            return (int) $this->payments($period)->sum('amount_refunded');
        });
    }

    public function failedPayments(Period $period): int
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            return Payment::where('status', PaymentStatus::Failed)
                ->whereBetween('created_at', [$period->start, $period->end])
                ->count();
        });
    }

    /** @return array<string, int> plan name ('' for payments without one) => net revenue, largest first */
    public function revenueByPlan(Period $period): array
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            return $this->payments($period)
                ->leftJoin('prices', 'prices.id', '=', 'payments.price_id')
                ->leftJoin('products', 'products.id', '=', 'prices.product_id')
                ->selectRaw('products.name as plan, sum(payments.amount - payments.amount_refunded) as total')
                ->groupBy('products.name')
                ->orderByDesc('total')
                ->toBase()
                ->get()
                ->mapWithKeys(fn (object $row): array => [(string) $row->plan => (int) $row->total])
                ->all();
        });
    }

    public function mrrAt(DateTimeInterface $at): int
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($at) {
            $monthly = "case prices.interval when 'day' then prices.amount * 365.0 / 12 when 'week' then prices.amount * 52.0 / 12 when 'year' then prices.amount / 12.0 else prices.amount * 1.0 end / coalesce(prices.interval_count, 1)";

            return (int) round((float) $this->activeQuery($at)
                ->join('prices', 'prices.id', '=', 'subscriptions.price_id')
                ->whereNotNull('prices.interval')
                ->where('prices.currency', $this->currency())
                ->sum(DB::raw($monthly)));
        });
    }

    /** Average monthly revenue per subscription, both in the default currency; 0 with none. */
    public function arpuAt(DateTimeInterface $at): int
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($at) {
            $count = $this->activeQuery($at)
                ->join('prices', 'prices.id', '=', 'subscriptions.price_id')
                ->whereNotNull('prices.interval')
                ->where('prices.currency', $this->currency())
                ->count();

            return $count ? intdiv($this->mrrAt($at), $count) : 0;
        });
    }

    public function activeAt(DateTimeInterface $at): int
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($at) {
            return $this->activeQuery($at)->count();
        });
    }

    public function newSubscriptions(Period $period): int
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            return Subscription::whereBetween('created_at', [$period->start, $period->end])->count();
        });
    }

    /** @return list<int|float> */
    public function newSubscriptionsSeries(Period $period): array
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            return $period->series(Subscription::query());
        });
    }

    public function cancelled(Period $period): int
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            return Subscription::whereBetween('cancelled_at', [$period->start, $period->end])->count();
        });
    }

    /** Cancelled in the period, as a share of those active when it started; null with none active. */
    public function churnRate(Period $period): ?float
    {
        $active = $this->activeAt($period->start);

        return $active === 0 ? null : round($this->cancelled($period) / $active * 100, 1);
    }

    /** Waiting on a payment right now. Not cached: the attention widget must be current, and it's one indexed count. */
    public function pastDue(): int
    {
        return Subscription::where('status', SubscriptionStatus::PastDue)->count();
    }

    /** Completed checkouts out of those that finished (pending ones are still open); null with none. */
    public function conversionRate(Period $period): ?float
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            $row = $this->finishedCheckouts()
                ->whereBetween('created_at', [$period->start, $period->end])
                ->selectRaw("{$this->completed()} as completed, count(*) as total")
                ->toBase()
                ->first();

            return (int) $row?->total === 0 ? null : round($row->completed / $row->total * 100, 1);
        });
    }

    /** @return list<int|float> */
    public function conversionSeries(Period $period): array
    {
        return $this->remember(__FUNCTION__, func_get_args(), function () use ($period) {
            $completed = $period->series($this->finishedCheckouts(), $this->completed());
            $total = $period->series($this->finishedCheckouts());

            return array_map(fn ($done, $all) => $all ? round($done / $all * 100, 1) : 0, $completed, $total);
        });
    }

    /**
     * Each widget loads in its own request, so answers are also cached for a few minutes
     * (fresh for 5, then served while they refresh, up to 10) for the next widget and visit.
     */
    private function remember(string $method, array $arguments, \Closure $compute): mixed
    {
        $key = 'billing:metrics:'.$method.':'.$this->currency()->value.':'.implode(',', array_map(
            fn (mixed $argument): string => match (true) {
                $argument instanceof Period => $argument->start->toDateTimeString().'..'.$argument->end->toDateTimeString(),
                $argument instanceof DateTimeInterface => $argument->format('Y-m-d H:i:s'),
                default => (string) $argument,
            },
            $arguments,
        ));

        if (! array_key_exists($key, $this->answered)) {
            $this->answered[$key] = Cache::flexible($key, [300, 600], $compute);
        }

        return $this->answered[$key];
    }

    /** Read once: it's a settings lookup. */
    private function currency(): Currency
    {
        return $this->currency ??= Currency::default();
    }

    /** @return Builder<Payment> */
    private function payments(Period $period): Builder
    {
        return $this->paidPayments()->whereBetween('payments.created_at', [$period->start, $period->end]);
    }

    /** Succeeded and refunded payments: a refund lowers revenue by what it gave back. */
    private function paidPayments(): Builder
    {
        return Payment::query()
            ->whereIn('payments.status', [PaymentStatus::Succeeded, PaymentStatus::Refunded])
            ->where('payments.currency', $this->currency());
    }

    private function net(): string
    {
        return 'payments.amount - payments.amount_refunded';
    }

    /**
     * Started by `$at` and not yet ended. Pending never started. A suspension ends it at
     * `grace_ends_at`, which suspending always sets. Without its own date, a suspended or
     * cancelled one ended by its last update (`updated_at`), never "still running".
     */
    private function activeQuery(DateTimeInterface $at): Builder
    {
        $suspended = SubscriptionStatus::Suspended->value;
        $cancelled = SubscriptionStatus::Cancelled->value;
        $endedAt = "coalesce(subscriptions.ends_at, subscriptions.cancelled_at, case subscriptions.status when '{$suspended}' then coalesce(subscriptions.grace_ends_at, subscriptions.updated_at) when '{$cancelled}' then subscriptions.updated_at end)";

        return Subscription::query()
            ->where('subscriptions.status', '!=', SubscriptionStatus::Pending)
            ->where('subscriptions.created_at', '<=', $at)
            ->where(fn (Builder $query) => $query
                ->whereRaw("{$endedAt} is null")
                ->orWhereRaw("{$endedAt} > ?", [$at]));
    }

    private function finishedCheckouts(): Builder
    {
        return CheckoutSession::whereIn('status', [CheckoutSessionStatus::Completed, CheckoutSessionStatus::Abandoned, CheckoutSessionStatus::Expired]);
    }

    private function completed(): string
    {
        return "sum(case when status = '".CheckoutSessionStatus::Completed->value."' then 1 else 0 end)";
    }
}
