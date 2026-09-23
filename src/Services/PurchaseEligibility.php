<?php

namespace Modules\Billing\Services;

use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Enums\PurchaseRefusal;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Subscription;

/**
 * Whether an owner may buy a price, and if not, why. Checkout and the pricing
 * page both ask here, so a button never offers what checkout would refuse.
 */
class PurchaseEligibility
{
    public function check(?BillingOwner $owner, Price $price): ?PurchaseRefusal
    {
        $plan = $price->product;

        if (! $plan) {
            return PurchaseRefusal::Unavailable;
        }

        if ($plan->kind === PlanKind::Free) {
            return PurchaseRefusal::NotForSale;
        }

        // Catalog imports skip the admin form, so a price that does not fit its
        // plan's kind can exist; it is never sold.
        if (! $price->is_active || ! $plan->is_active || $price->provider_price_id === null
            || $plan->kind->isRecurring() !== ($price->interval !== null)) {
            return PurchaseRefusal::Unavailable;
        }

        $account = $owner?->billingAccount();

        if (! $account) {
            return null;
        }

        $lifetimePlans = $account->lifetimePurchases()->map(fn ($payment) => $payment->price?->plan)->filter();

        if ($plan->kind === PlanKind::Lifetime) {
            return $lifetimePlans->contains('id', $plan->id) ? PurchaseRefusal::Current : null;
        }

        if ($plan->kind !== PlanKind::Subscription) {
            return null;
        }

        $replaced = $lifetimePlans->pluck('replaces_product_id')->filter()->all();
        // Live, not access-granting: a suspended subscription is still one subscription.
        $subscribedPlanId = $account->currentSubscription()?->price?->product_id;

        return match (true) {
            $subscribedPlanId === $plan->id => PurchaseRefusal::Current,
            in_array($plan->id, $replaced, true) => PurchaseRefusal::Included,
            $subscribedPlanId !== null && in_array($subscribedPlanId, $replaced, true) => PurchaseRefusal::AfterCurrentEnds,
            $subscribedPlanId !== null => PurchaseRefusal::ChangeInstead,
            default => null,
        };
    }

    /**
     * A subscription the owner's lifetime plan replaces is running out its paid
     * period. The app offers no way back into it — no plan change, no resume —
     * though the provider stays the source of truth if the customer finds one.
     */
    public function isReplacedByLifetime(Subscription $subscription): bool
    {
        $account = $subscription->customer;

        return $account !== null && $account->lifetimePurchases()
            ->contains(fn ($payment) => $payment->price?->plan?->replaces_product_id === $subscription->price?->product_id);
    }
}
