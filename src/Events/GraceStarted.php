<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Subscription;

/** A payment failed and the subscription now has a deadline to fix it by. */
class GraceStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
    ) {}
}
