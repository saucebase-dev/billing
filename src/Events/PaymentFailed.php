<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Payment;

class PaymentFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Payment $payment,
    ) {}
}
