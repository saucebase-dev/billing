<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Product;

class BillingPlansController
{
    public function __invoke(Request $request): Response
    {
        return inertia('Billing::Plans', [
            'products' => Product::displayable()->get(),
            'currentProductId' => $request->user()?->billingCustomer
                ?->subscriptions()
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
                ->latest()
                ->first()
                ?->price
                ?->product_id,
        ])->withSSR();
    }
}
