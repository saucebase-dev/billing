<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Collection;
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Enums\PlanAction;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Enums\PurchaseRefusal;
use Modules\Billing\Models\Product;

/**
 * The button each pricing card shows, decided on the server so both stacks
 * render the same thing. `buy` only appears where `PurchaseEligibility` —
 * the rule checkout enforces — lets the price be bought; everything else is
 * a label for why not.
 */
class PlanActions
{
    public function __construct(
        private PurchaseEligibility $eligibility,
    ) {}

    /**
     * @param  Collection<int, Product>  $plans  With their displayed prices loaded.
     * @return array{priceActions: array<int, PlanAction>, productActions: array<int, PlanAction>}
     */
    public function for(?BillingOwner $owner, Collection $plans): array
    {
        $priceActions = [];
        $productActions = [];
        // Once per page, not once per price, and only if a trial is on offer: it is two queries.
        $hasTrialed = $plans->contains(fn (Product $plan) => $plan->trial_days > 0)
            && ($owner?->billingAccount()?->hasTrialed() ?? false);

        foreach ($plans as $plan) {
            if ($plan->kind === PlanKind::Free) {
                $action = $this->freePlanAction($owner);
            } elseif (filled($plan->metadata['cta_url'] ?? null)) {
                $action = PlanAction::Contact;
            } else {
                $action = null;
            }

            foreach ($plan->prices as $price) {
                $refusal = $this->eligibility->check($owner, $price);

                // A trial is still a purchase; only the button reads differently,
                // so eligibility stays out of it.
                $priceActions[$price->id] = $action
                    ?? ($refusal === null && $plan->trial_days > 0 && ! $hasTrialed ? PlanAction::Trial : $this->priceAction($refusal));
            }

            if ($plan->prices->isEmpty()) {
                $productActions[$plan->id] = $action ?? PlanAction::Unavailable;
            }
        }

        return ['priceActions' => $priceActions, 'productActions' => $productActions];
    }

    private function priceAction(?PurchaseRefusal $refusal): PlanAction
    {
        return match ($refusal) {
            null => PlanAction::Buy,
            PurchaseRefusal::Current => PlanAction::Current,
            PurchaseRefusal::Included => PlanAction::Included,
            PurchaseRefusal::ChangeInstead => PlanAction::Change,
            PurchaseRefusal::AfterCurrentEnds => PlanAction::Later,
            PurchaseRefusal::Unavailable, PurchaseRefusal::NotForSale => PlanAction::Unavailable,
        };
    }

    /** Everyone has the free plan: guests sign up for it, and a paid plan includes it. */
    private function freePlanAction(?BillingOwner $owner): PlanAction
    {
        if (! $owner) {
            return PlanAction::Signup;
        }

        return $owner->hasPaidPlan() ? PlanAction::Included : PlanAction::Current;
    }
}
