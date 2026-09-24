<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\GraceStarted;
use Modules\Billing\Notifications\GraceStartedNotification;

class SendGraceStartedNotification implements ShouldQueue
{
    public function handle(GraceStarted $event): void
    {
        $event->subscription->customer->owner?->notify(new GraceStartedNotification($event->subscription));
    }
}
