<?php

namespace Modules\Billing\Listeners;

use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use Modules\Billing\Events\BillingEvent;

/** Mails the customer's owner whatever `config('billing.notifications')` maps the event to. */
class SendBillingNotifications
{
    public function handle(BillingEvent $event): void
    {
        foreach (config('billing.notifications.'.$event::class, []) as $class) {
            if (! is_subclass_of($class, Notification::class)) {
                throw new InvalidArgumentException(sprintf('[%s] in billing.notifications is not a notification.', $class));
            }

            $event->customer()
                ->owner
                ?->notify(new $class($event->subject()));
        }
    }
}
