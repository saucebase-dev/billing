<?php

namespace Modules\Billing\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\Billing\Data\Entitlements;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Relations\OwnerAccount;
use Modules\Billing\Models\Subscription;

/**
 * Makes an Eloquent model a billing owner. Pair it with `implements BillingOwner`.
 *
 * @property-read Customer|null $billingCustomer
 */
trait Billable
{
    /**
     * Billing history outlives its owner: deleting one detaches its account
     * instead. Only deletes that fire model events reach this; a query-builder
     * delete leaves a dangling `owner_id`. A soft delete keeps the link, since
     * the owner may be restored.
     */
    public static function bootBillable(): void
    {
        static::deleted(function (self $owner): void {
            if (method_exists($owner, 'isForceDeleting') && ! $owner->isForceDeleting()) {
                return;
            }

            $owner->billingCustomer()->getQuery()->update(['owner_type' => null, 'owner_id' => null]);
        });
    }

    /**
     * @return OwnerAccount<$this>
     */
    public function billingCustomer(): MorphOne
    {
        $customers = (new Customer)->getTable();

        return new OwnerAccount((new Customer)->newQuery(), $this, "{$customers}.owner_type", "{$customers}.owner_id", $this->getKeyName());
    }

    public function canManageBilling(User $user): bool
    {
        return $user->is($this);
    }

    public function billingAccount(): ?Customer
    {
        return $this->billingCustomer;
    }

    public function entitlements(): Entitlements
    {
        $entitlements = Product::where('kind', PlanKind::Free)->first()?->entitlements() ?? Entitlements::none();
        $subscribed = $this->accessGrantingSubscription()?->price?->plan;

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
        return $this->accessGrantingSubscription()?->price->plan->name
            ?? $this->heldLifetimePurchases()->first()?->price->plan->name
            ?? Product::where('kind', PlanKind::Free)->value('name');
    }

    public function hasPaidPlan(): bool
    {
        return $this->accessGrantingSubscription() !== null || $this->heldLifetimePurchases()->isNotEmpty();
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
    private function accessGrantingSubscription(): ?Subscription
    {
        return once(function (): ?Subscription {
            $subscription = $this->billingAccount()?->currentSubscription();

            return $subscription?->grantsAccess() ? $subscription : null;
        });
    }

    /** @return Collection<int, Payment> */
    private function heldLifetimePurchases(): Collection
    {
        return once(fn () => $this->billingAccount()?->lifetimePurchases() ?? new Collection);
    }
}
