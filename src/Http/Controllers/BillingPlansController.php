<?php

namespace Modules\Billing\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Billing\Models\Product;

class BillingPlansController
{
    public function __invoke(Request $request): Response
    {
        return inertia('Billing::Plans', [
            'products' => Product::displayable()->get(),
            'access' => $this->access($request->user()),
        ])->withSSR();
    }

    /**
     * What the visitor already has, which decides every card's button: their
     * own plan, plans to change to, or plans lifetime access already covers.
     *
     * `free` is a signed-in visitor with nothing bought; `null` is a guest.
     *
     * @return array{productId: int|null, kind: 'subscription'|'lifetime'|'free'|null}
     */
    private function access(?User $user): array
    {
        if (! $user) {
            return ['productId' => null, 'kind' => null];
        }

        $customer = $user->billingCustomer;

        // Lifetime first: a subscriber who upgraded keeps the subscription until
        // its paid period ends, but what they own now is lifetime.
        if ($payment = $customer?->lifetimePayment()) {
            return ['productId' => $payment->price?->product_id, 'kind' => 'lifetime'];
        }

        if ($subscription = $customer?->currentSubscription()) {
            return ['productId' => $subscription->price->product_id, 'kind' => 'subscription'];
        }

        return ['productId' => $this->freeProductId(), 'kind' => 'free'];
    }

    /**
     * The plan a signed-in visitor with nothing bought is on: the first one,
     * in the admin's order, whose only active prices are free. Several free
     * plans would otherwise all claim to be theirs.
     */
    private function freeProductId(): ?int
    {
        return Product::displayable()
            ->whereHas('prices', fn ($prices) => $prices->where('is_active', true)->where('amount', 0))
            ->whereDoesntHave('prices', fn ($prices) => $prices->where('is_active', true)->where('amount', '>', 0))
            ->value('id');
    }
}
