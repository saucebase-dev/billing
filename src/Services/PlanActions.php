<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Collection;
use Modules\Billing\Contracts\BillingOwner;
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
     * @return array{priceActions: array<int, string>, productActions: array<int, string>}
     */
    public function for(?BillingOwner $owner, Collection $plans): array
    {
        $priceActions = [];
        $productActions = [];

        foreach ($plans as $plan) {
            if ($plan->kind === PlanKind::Free) {
                $action = $this->freePlanAction($owner);
            } elseif (filled($plan->metadata['cta_url'] ?? null)) {
                $action = 'contact';
            } else {
                $action = null;
            }

            foreach ($plan->prices as $price) {
                $priceActions[$price->id] = $action ?? $this->priceAction($this->eligibility->check($owner, $price));
            }

            if ($plan->prices->isEmpty()) {
                $productActions[$plan->id] = $action ?? 'unavailable';
            }
        }

        return ['priceActions' => $priceActions, 'productActions' => $productActions];
    }

    private function priceAction(?PurchaseRefusal $refusal): string
    {
        return match ($refusal) {
            null => 'buy',
            PurchaseRefusal::Current => 'current',
            PurchaseRefusal::Included => 'included',
            PurchaseRefusal::ChangeInstead => 'change',
            PurchaseRefusal::AfterCurrentEnds => 'later',
            PurchaseRefusal::Unavailable, PurchaseRefusal::NotForSale => 'unavailable',
        };
    }

    /** Everyone has the free plan: guests sign up for it, and a paid plan includes it. */
    private function freePlanAction(?BillingOwner $owner): string
    {
        if (! $owner) {
            return 'signup';
        }

        return $owner->hasPaidPlan() ? 'included' : 'current';
    }
}
