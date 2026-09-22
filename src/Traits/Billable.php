<?php

namespace Modules\Billing\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Billing\Data\Entitlements;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;

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
        $subscribed = $this->heldSubscription()?->price?->plan;

        if ($subscribed) {
            $entitlements = $entitlements->merge($subscribed->entitlements());
        }

        foreach ($this->heldLifetimePurchases() as $purchase) {
            if ($purchase->price?->plan) {
                $entitlements = $entitlements->merge($purchase->price->plan->entitlements());
            }
        }

        return $entitlements;
    }

    public function planName(): ?string
    {
        return $this->heldSubscription()?->price->plan->name
            ?? $this->heldLifetimePurchases()->first()?->price->plan->name
            ?? Product::where('kind', PlanKind::Free)->value('name');
    }

    public function hasPaidPlan(): bool
    {
        return $this->heldSubscription() !== null || $this->heldLifetimePurchases()->isNotEmpty();
    }

    public function canUseFeature(string $feature): bool
    {
        return $this->entitlements()->allows($feature);
    }

    public function planLimit(string $key): ?int
    {
        return $this->entitlements()->limit($key);
    }

    /**
     * Read once per owner instance: the plan name and the Upgrade menu item both
     * ask on every page. A fresh instance (`fresh()`, the next request) reads again.
     */
    private function heldSubscription(): ?Subscription
    {
        return once(fn () => $this->billingAccount()?->currentSubscription());
    }

    /** @return Collection<int, Payment> */
    private function heldLifetimePurchases(): Collection
    {
        return once(fn () => $this->billingAccount()?->lifetimePurchases() ?? new Collection);
    }
}
