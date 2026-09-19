<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\SubscriptionResumed;
use Modules\Billing\Notifications\SubscriptionResumedNotification;

class SendSubscriptionResumedNotification implements ShouldQueue
{
    public function handle(SubscriptionResumed $event): void
    {
        $user = $event->subscription->customer->user;

        $user?->notify(new SubscriptionResumedNotification($event->subscription));
    }
}
