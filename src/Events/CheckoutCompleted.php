<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\CheckoutSession;

class CheckoutCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CheckoutSession $checkoutSession,
    ) {}
}
