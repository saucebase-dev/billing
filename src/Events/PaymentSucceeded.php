<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Payment;

class PaymentSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Payment $payment,
    ) {}
}
