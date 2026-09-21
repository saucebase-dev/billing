<?php

namespace Modules\Billing\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Models\Customer;
use Modules\Billing\Services\BillingService;

class BillingPortalController
{
    public function __construct(
        private BillingService $billingService,
    ) {}

    public function __invoke(): RedirectResponse
    {
        $customer = Customer::where('user_id', Auth::id())->first();

        if (! $customer) {
            return redirect()->route('settings.billing')->with('toast', [
                'type' => 'error',
                'message' => __('No billing account found. Please subscribe to a plan first.'),
            ]);
        }

        $url = $this->billingService->getManagementUrl(Auth::user());

        return redirect()->away($url);
    }

    /**
     * Only the signed-in user's own subscription: nothing in the request says
     * which one, so there is nobody else's to reach.
     */
    public function changePlan(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $subscription = $user->billingCustomer?->currentSubscription();

        if (! $subscription) {
            abort(404);
        }

        return redirect()->away($this->billingService->getPlanChangeUrl($subscription));
    }
}
