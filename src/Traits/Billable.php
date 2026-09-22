<?php

namespace Modules\Billing\Traits;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Billing\Data\Entitlements;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Product;

/**
 * Makes a model a billing owner. Pair it with `implements BillingOwner`.
 */
trait Billable
{
    /**
     * @return HasOne<Customer, $this>
     */
    public function billingCustomer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function billingAccount(): ?Customer
    {
        return $this->billingCustomer;
    }

    public function entitlements(): Entitlements
    {
        $entitlements = Product::where('kind', PlanKind::Free)->first()?->entitlements() ?? Entitlements::none();
        $account = $this->billingAccount();

        if (! $account) {
            return $entitlements;
        }

        $subscribed = $account->currentSubscription()?->price?->plan;

        if ($subscribed) {
            $entitlements = $entitlements->merge($subscribed->entitlements());
        }

        foreach ($account->lifetimePurchases() as $purchase) {
            if ($purchase->price?->plan) {
                $entitlements = $entitlements->merge($purchase->price->plan->entitlements());
            }
        }

        return $entitlements;
    }

    public function planName(): ?string
    {
        $account = $this->billingAccount();

        return $account?->currentSubscription()?->price->plan->name
            ?? $account?->lifetimePurchases()->first()?->price->plan->name
            ?? Product::where('kind', PlanKind::Free)->value('name');
    }

    public function hasPaidPlan(): bool
    {
        $account = $this->billingAccount();

        return $account !== null
            && ($account->currentSubscription() !== null || $account->lifetimePurchases()->isNotEmpty());
    }

    public function canUseFeature(string $feature): bool
    {
        return $this->entitlements()->allows($feature);
    }

    public function planLimit(string $key): ?int
    {
        return $this->entitlements()->limit($key);
    }
}
