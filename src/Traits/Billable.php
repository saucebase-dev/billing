<?php

namespace Modules\Billing\Traits;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Billing\Models\Customer;

trait Billable
{
    /**
     * Get the customer's billing information.
     *
     * @return HasOne<Customer, $this>
     */
    public function billingCustomer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }
}
