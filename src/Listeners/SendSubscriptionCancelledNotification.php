<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\SubscriptionCancelled;
use Modules\Billing\Notifications\SubscriptionCancelledNotification;

class SendSubscriptionCancelledNotification implements ShouldQueue
{
    public function handle(SubscriptionCancelled $event): void
    {
        $event->subscription->customer->owner?->notify(new SubscriptionCancelledNotification($event->subscription));
    }
}
