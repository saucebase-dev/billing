<?php

namespace Modules\Billing\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\Billing\Data\Entitlements;
use Modules\Billing\Models\Customer;

/**
 * Whoever pays and holds the plans. A user by default, through `Billable`; a
 * workspace can implement the same contract, and nothing that decides access
 * or purchases needs to change. `BillingOwners` says which owner a user acts for.
 */
interface BillingOwner
{
    /**
     * The relation checkout creates the owner's account through.
     *
     * @return MorphOne<Customer, covariant \Illuminate\Database\Eloquent\Model>
     */
    public function billingCustomer(): MorphOne;

    /** The owner's account at the payment provider, if it has bought anything. */
    public function billingAccount(): ?Customer;

    /** Every plan the owner holds, combined, with the free plan as the baseline. */
    public function entitlements(): Entitlements;

    /** A current subscription or a lifetime plan: anything beyond the free plan. */
    public function hasPaidPlan(): bool;

    /** The plan to name: the subscription's, else a lifetime plan's, else the free plan's. */
    public function planName(): ?string;

    public function canUseFeature(string $feature): bool;

    /** Null is unlimited; a limit no plan mentions is zero. */
    public function planLimit(string $key): ?int;

    /** Whether this user may buy, cancel, resume or open the portal for this owner. */
    public function canManageBilling(User $user): bool;

    /**
     * Billing emails go to the owner: `Notifiable`, plus a mail route for an
     * owner without an `email`.
     *
     * @param  mixed  $instance
     */
    public function notify($instance);
}
