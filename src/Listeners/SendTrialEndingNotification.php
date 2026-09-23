<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\TrialEnding;
use Modules\Billing\Notifications\TrialEndingNotification;

class SendTrialEndingNotification implements ShouldQueue
{
    public function handle(TrialEnding $event): void
    {
        $user = $event->subscription->customer->user;

        $user?->notify(new TrialEndingNotification($event->subscription));
    }
}
