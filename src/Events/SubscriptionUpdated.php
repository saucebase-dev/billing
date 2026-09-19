<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Subscription;

class SubscriptionUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
    ) {}
}
