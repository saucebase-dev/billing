<?php

namespace Modules\Billing\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Billing\Events\PaymentSucceeded;
use Modules\Billing\Notifications\PaymentSucceededNotification;

class SendPaymentSucceededNotification implements ShouldQueue
{
    public function handle(PaymentSucceeded $event): void
    {
        $event->payment->customer->owner?->notify(new PaymentSucceededNotification($event->payment));
    }
}
