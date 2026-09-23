<?php

namespace Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\BillingService;

/**
 * Suspend subscriptions whose grace window has closed.
 *
 * Access is already gone the moment the deadline passes — `grantsAccess()` says
 * so — and this is what records it and tells the customer. The provider is never
 * called: it owns dunning, and may yet recover the subscription.
 */
class EndGracePeriodsCommand extends Command
{
    protected $signature = 'billing:end-grace-periods';

    protected $description = 'Suspend subscriptions whose grace period has run out';

    public function handle(BillingService $billing): int
    {
        $suspended = 0;

        Subscription::where('status', SubscriptionStatus::PastDue)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '<=', now())
            ->lazyById()
            ->each(function (Subscription $subscription) use ($billing, &$suspended): void {
                $suspended += $billing->suspendIfGraceHasRunOut($subscription) ? 1 : 0;
            });

        $this->info("Suspended {$suspended} subscription(s).");

        return self::SUCCESS;
    }
}
