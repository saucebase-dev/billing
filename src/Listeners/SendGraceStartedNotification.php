<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\GraceStarted;
use Modules\Billing\Notifications\GraceStartedNotification;

class SendGraceStartedNotification implements ShouldQueue
{
    public function handle(GraceStarted $event): void
    {
        $user = $event->subscription->customer->user;

        $user?->notify(new GraceStartedNotification($event->subscription));
    }
}
