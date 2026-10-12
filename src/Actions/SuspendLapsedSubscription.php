<?php

namespace Modules\Billing\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Subscription;

/** Suspend a past-due subscription whose grace period has run out. */
class SuspendLapsedSubscription
{
    /**
     * Suspend a subscription whose grace window has closed.
     *
     * The status is re-checked under the row's lock, so a recovery that landed
     * between the sweeper's query and this call wins rather than being undone.
     */
    public function handle(Subscription $subscription): bool
    {
        $suspended = DB::transaction(function () use ($subscription): ?Subscription {
            $current = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== SubscriptionStatus::PastDue || ! $current->grace_ends_at?->isPast()) {
                return null;
            }

            $current->moveTo(SubscriptionStatus::Suspended);

            return $current;
        });

        if ($suspended) {
            $suspended->announceDelinquency();
        }

        return $suspended !== null;
    }
}
