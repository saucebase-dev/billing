<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\PaymentSucceeded;
use Modules\Billing\Notifications\PaymentSucceededNotification;

class SendPaymentSucceededNotification implements ShouldQueue
{
    public function handle(PaymentSucceeded $event): void
    {
        $user = $event->payment->customer->user;

        $user?->notify(new PaymentSucceededNotification($event->payment));
    }
}
