<?php

namespace Modules\Billing\Contracts;

use Modules\Billing\Data\Entitlements;
use Modules\Billing\Models\Customer;

/**
 * Whoever pays and holds the plans. A user by default, through `Billable`; a
 * workspace can implement the same contract, and nothing that decides access
 * or purchases needs to change.
 */
interface BillingOwner
{
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
}
