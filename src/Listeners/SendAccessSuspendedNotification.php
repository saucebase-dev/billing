<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\AccessSuspended;
use Modules\Billing\Notifications\AccessSuspendedNotification;

class SendAccessSuspendedNotification implements ShouldQueue
{
    public function handle(AccessSuspended $event): void
    {
        $event->subscription->customer->owner?->notify(new AccessSuspendedNotification($event->subscription));
    }
}
