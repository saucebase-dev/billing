<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\CheckoutSession;

/** A checkout was recorded as completed, by its webhook or the buyer's return, whichever came first. */
class CheckoutCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CheckoutSession $checkoutSession,
    ) {}
}
