<?php

namespace Modules\Billing\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\PlanAction;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Enums\PurchaseRefusal;
use Modules\Billing\Models\Price;
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
     * @param  User|null  $viewer  Who is looking, when that may not be the owner: what they
     *                             cannot change becomes `OwnerOnly`.
     * @return array{priceActions: array<int, PlanAction>, productActions: array<int, PlanAction>}
     */
    public function for(?BillingOwner $owner, Collection $plans, ?User $viewer = null): array
    {
        $actions = $this->actionsFor($owner, $plans);

        if ($owner === null || $viewer === null || $owner->canManageBilling($viewer)) {
            return $actions;
        }

        return array_map(fn (array $byId): array => array_map(
            fn (PlanAction $action): PlanAction => in_array($action, [PlanAction::Buy, PlanAction::BuyAgain, PlanAction::Trial, PlanAction::Change], true) ? PlanAction::OwnerOnly : $action,
            $byId,
        ), $actions);
    }

    /**
     * @param  Collection<int, Product>  $plans
     * @return array{priceActions: array<int, PlanAction>, productActions: array<int, PlanAction>}
     */
    private function actionsFor(?BillingOwner $owner, Collection $plans): array
    {
        $priceActions = [];
        $productActions = [];
        // Once per page, not once per price, and only if a trial is on offer: it is two queries.
        $hasTrialed = $plans->contains(fn (Product $plan) => $plan->trial_days > 0)
            && ($owner?->billingAccount()?->hasTrialed() ?? false);
        $boughtOneOffs = $this->boughtOneOffs($owner, $plans);

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
                    ?? match (true) {
                        $refusal !== null => $this->priceAction($refusal),
                        $plan->trial_days > 0 && ! $hasTrialed => PlanAction::Trial,
                        in_array($plan->id, $boughtOneOffs, true) => PlanAction::BuyAgain,
                        default => PlanAction::Buy,
                    };
            }

            if ($plan->prices->isEmpty()) {
                $productActions[$plan->id] = $action ?? PlanAction::Unavailable;
            }
        }

        return ['priceActions' => $priceActions, 'productActions' => $productActions];
    }

    /**
     * The one-offs on this page the owner holds a payment for, refunds aside. One query.
     *
     * @param  Collection<int, Product>  $plans
     * @return list<int>
     */
    private function boughtOneOffs(?BillingOwner $owner, Collection $plans): array
    {
        $oneOffs = $plans->filter(fn (Product $plan) => $plan->kind === PlanKind::OneOff);
        $account = $oneOffs->isEmpty() ? null : $owner?->billingAccount();

        if ($account === null) {
            return [];
        }

        // Through every price the plan ever had: a page shows only active ones, and a
        // purchase under a replaced price is still a purchase.
        return Price::query()
            ->whereIn('product_id', $oneOffs->pluck('id'))
            ->whereIn('id', $account->payments()->where('status', PaymentStatus::Succeeded)->select('price_id'))
            ->distinct()
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();
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
