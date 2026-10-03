<?php

namespace Modules\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;

abstract class PaymentEvent implements BillingEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Payment $payment,
    ) {}

    public function customer(): Customer
    {
        return $this->payment->customer;
    }

    public function subject(): Payment
    {
        return $this->payment;
    }
}
