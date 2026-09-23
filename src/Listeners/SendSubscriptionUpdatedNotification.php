<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Events\SubscriptionUpdated;
use Modules\Billing\Notifications\SubscriptionUpdatedNotification;

class SendSubscriptionUpdatedNotification implements ShouldQueue
{
    public function handle(SubscriptionUpdated $event): void
    {
        $subscription = $event->subscription;

        // Only a cancellation scheduled for the period end is news here. Falling
        // behind on payment has its own mail, which names the date access ends.
        if (! $subscription->cancelled_at || $subscription->status !== SubscriptionStatus::Active) {
            return;
        }

        $subscription->customer->user?->notify(new SubscriptionUpdatedNotification($subscription));
    }
}
