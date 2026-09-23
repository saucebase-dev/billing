<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Subscription;

/** The provider says this trial ends soon, and will charge the card when it does. */
class TrialEnding
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
    ) {}
}
