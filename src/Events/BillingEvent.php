<?php

namespace Modules\Billing\Events;

use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;

/** An event `config('billing.notifications')` can map to mail for the customer's owner. */
interface BillingEvent
{
    public function customer(): Customer;

    /** What the mapped notifications are built from. */
    public function subject(): Subscription|Payment;
}
