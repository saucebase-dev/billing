<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Subscription;

/** The grace period ran out: the subscription no longer grants what it sells. */
class AccessSuspended
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
    ) {}
}
