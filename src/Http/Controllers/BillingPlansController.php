<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\BillingOwners;
use Modules\Billing\Services\PlanActions;

class BillingPlansController
{
    public function __invoke(Request $request, PlanActions $actions, BillingOwners $owners): Response
    {
        $products = Product::displayable()->get();

        return inertia('Billing::Plans', [
            'products' => $products,
            ...$actions->for($owners->for($request->user()), $products),
        ])->withSSR();
    }
}
