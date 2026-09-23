<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\AccessSuspended;
use Modules\Billing\Notifications\AccessSuspendedNotification;

class SendAccessSuspendedNotification implements ShouldQueue
{
    public function handle(AccessSuspended $event): void
    {
        $user = $event->subscription->customer->user;

        $user?->notify(new AccessSuspendedNotification($event->subscription));
    }
}
