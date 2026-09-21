<?php

namespace Modules\Billing\Database\Seeders;

use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\InvoiceStatus;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Subscription;

/**
 * What the demo customers bought, and every charge since.
 *
 * The billing dashboard reads four things — MRR, active subscriptions, revenue in
 * a period, and checkout conversion — so this seeds all four: a subscription per
 * customer, one payment and invoice per billing period from signup to now, and
 * enough abandoned checkouts to keep the conversion rate short of a suspicious
 * 100%.
 *
 * Runs after DemoCustomerSeeder and DemoProductSeeder, and does nothing without
 * them.
 */
class DemoSubscriptionSeeder extends Seeder
{
    /** Every ninth customer churned, every thirteenth is behind on payment. */
    private const CANCELS_EVERY = 9;

    private const FALLS_BEHIND_EVERY = 13;

    /** One in three signups abandoned a checkout before the one that stuck. */
    private const ABANDONS_EVERY = 3;

    public function run(): void
    {
        $customers = Customer::where('email', 'like', 'demo-customer-%@example.com')
            ->orderBy('created_at')
            ->get();

        $prices = $this->prices();

        if ($customers->isEmpty() || $prices === []) {
            return;
        }

        foreach ($customers->values() as $index => $customer) {
            $price = $prices[$index % count($prices)];
            $startedAt = $customer->created_at;

            $this->seedAbandonedCheckouts($index, $customer, $price, $startedAt);

            $session = $this->completedCheckout($index, $customer, $price, $startedAt);
            $subscription = $this->subscription($index, $customer, $price, $startedAt);

            $this->billingHistory($index, $customer, $subscription, $price, $startedAt);
        }
    }

    /**
     * The paid plans, cheapest first. Looked up by plan and interval rather than by
     * Stripe price ID, so the demo account's IDs live only in DemoProductSeeder.
     *
     * @return list<Price>
     */
    private function prices(): array
    {
        return collect([
            ['pro', 'month'],
            ['team', 'month'],
            ['pro', 'year'],
            ['team', 'year'],
        ])
            ->map(fn (array $plan) => Price::whereHas('product', fn ($query) => $query->where('slug', $plan[0]))
                ->where('interval', $plan[1])
                ->where('amount', '>', 0)
                ->first())
            ->filter()
            ->values()
            ->all();
    }

    private function seedAbandonedCheckouts(int $index, Customer $customer, Price $price, CarbonInterface $startedAt): void
    {
        if ($index % self::ABANDONS_EVERY !== 0) {
            return;
        }

        // Expired and abandoned read the same on the dashboard; alternating them
        // keeps the conversion chart from looking hand-made.
        CheckoutSession::updateOrCreate(
            ['provider' => 'stripe', 'provider_session_id' => "cs_demo_lost_{$index}"],
            [
                'customer_id' => $customer->id,
                'price_id' => $price->id,
                'status' => $index % 2 === 0
                    ? CheckoutSessionStatus::Abandoned
                    : CheckoutSessionStatus::Expired,
                'expires_at' => $startedAt->copy()->subDays(2)->addHour(),
                'created_at' => $startedAt->copy()->subDays(2),
                'updated_at' => $startedAt->copy()->subDays(2),
            ],
        );
    }

    private function completedCheckout(int $index, Customer $customer, Price $price, CarbonInterface $startedAt): CheckoutSession
    {
        return CheckoutSession::updateOrCreate(
            ['provider' => 'stripe', 'provider_session_id' => "cs_demo_won_{$index}"],
            [
                'customer_id' => $customer->id,
                'price_id' => $price->id,
                'status' => CheckoutSessionStatus::Completed,
                'expires_at' => $startedAt->copy()->addHour(),
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ],
        );
    }

    private function subscription(int $index, Customer $customer, Price $price, CarbonInterface $startedAt): Subscription
    {
        $cancelled = $index % self::CANCELS_EVERY === 0 && $index > 0;
        $behind = ! $cancelled && $index % self::FALLS_BEHIND_EVERY === 0 && $index > 0;

        $periodStart = $this->currentPeriodStart($price, $startedAt);

        return Subscription::updateOrCreate(
            ['provider' => 'stripe', 'provider_subscription_id' => "sub_demo_{$index}"],
            [
                'customer_id' => $customer->id,
                'price_id' => $price->id,
                'payment_method_id' => $customer->paymentMethods()->value('id'),
                'status' => match (true) {
                    $cancelled => SubscriptionStatus::Cancelled,
                    $behind => SubscriptionStatus::PastDue,
                    default => SubscriptionStatus::Active,
                },
                'current_period_starts_at' => $periodStart,
                'current_period_ends_at' => $this->nextPeriod($price, $periodStart),
                'cancelled_at' => $cancelled ? $periodStart : null,
                'ends_at' => $cancelled ? $this->nextPeriod($price, $periodStart) : null,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ],
        );
    }

    /**
     * One payment and invoice per billing period between signup and today. A
     * cancelled subscription stops billing; a past-due one fails its last charge.
     */
    private function billingHistory(int $index, Customer $customer, Subscription $subscription, Price $price, CarbonInterface $startedAt): void
    {
        $chargedAt = $startedAt->copy();
        $period = 0;

        while ($chargedAt->lessThanOrEqualTo(now())) {
            if ($subscription->status === SubscriptionStatus::Cancelled && $chargedAt->greaterThan($subscription->cancelled_at)) {
                break;
            }

            $isLast = $this->nextPeriod($price, $chargedAt)->greaterThan(now());
            $failed = $isLast && $subscription->status === SubscriptionStatus::PastDue;

            $payment = Payment::updateOrCreate(
                ['provider' => 'stripe', 'provider_payment_id' => "pi_demo_{$index}_{$period}"],
                [
                    'customer_id' => $customer->id,
                    'subscription_id' => $subscription->id,
                    'price_id' => $price->id,
                    'payment_method_id' => $subscription->payment_method_id,
                    'currency' => Currency::default(),
                    'amount' => $price->amount,
                    'status' => $failed ? PaymentStatus::Failed : PaymentStatus::Succeeded,
                    'failure_code' => $failed ? 'card_declined' : null,
                    'failure_message' => $failed ? 'Your card was declined.' : null,
                    'created_at' => $chargedAt,
                    'updated_at' => $chargedAt,
                ],
            );

            Invoice::updateOrCreate(
                ['provider' => 'stripe', 'provider_invoice_id' => "in_demo_{$index}_{$period}"],
                [
                    'customer_id' => $customer->id,
                    'subscription_id' => $subscription->id,
                    'payment_id' => $payment->id,
                    'number' => sprintf('DEMO-%04d-%02d', $index, $period),
                    'currency' => Currency::default(),
                    'subtotal' => $price->amount,
                    'total' => $price->amount,
                    'status' => $failed ? InvoiceStatus::Unpaid : InvoiceStatus::Paid,
                    'due_at' => $chargedAt,
                    'paid_at' => $failed ? null : $chargedAt,
                    'created_at' => $chargedAt,
                    'updated_at' => $chargedAt,
                ],
            );

            $chargedAt = $this->nextPeriod($price, $chargedAt);
            $period++;
        }
    }

    private function currentPeriodStart(Price $price, CarbonInterface $startedAt): CarbonInterface
    {
        $start = $startedAt->copy();

        while ($this->nextPeriod($price, $start)->lessThanOrEqualTo(now())) {
            $start = $this->nextPeriod($price, $start);
        }

        return $start;
    }

    private function nextPeriod(Price $price, CarbonInterface $from): CarbonInterface
    {
        // Never zero: the billing loop advances by this and would not terminate.
        $count = max(1, $price->interval_count ?? 1);

        return $price->interval === 'year'
            ? $from->copy()->addYears($count)
            : $from->copy()->addMonths($count);
    }
}
