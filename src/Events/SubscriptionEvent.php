<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Subscription;

abstract class SubscriptionEvent implements BillingEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
    ) {}

    public function customer(): Customer
    {
        return $this->subscription->customer;
    }

    public function subject(): Subscription
    {
        return $this->subscription;
    }
}
