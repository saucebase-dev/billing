<?php

namespace Modules\Billing\Listeners;

use App\Enums\Role;
use Modules\Billing\Events\SubscriptionCancelled;
use Modules\Billing\Events\SubscriptionCreated;
use Modules\Billing\Events\SubscriptionUpdated;
use Modules\Billing\Models\Customer;

/**
 * The subscriber role follows the customer's access as a whole — a current
 * subscription or a lifetime purchase — never one subscription's status, so
 * ending a subscription cannot take the role from a lifetime owner.
 */
class SyncSubscriberRole
{
    public function handle(SubscriptionCreated|SubscriptionUpdated|SubscriptionCancelled $event): void
    {
        if ($event->subscription->customer) {
            $this->sync($event->subscription->customer);
        }
    }

    public function sync(Customer $customer): void
    {
        $user = $customer->user;

        if (! $user) {
            return;
        }

        if ($customer->hasAccess()) {
            $user->assignRole(Role::SUBSCRIBER);
        } else {
            $user->removeRole(Role::SUBSCRIBER);
        }
    }
}
