<?php

namespace Modules\Billing\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Billing\Exceptions\GatewayOperationFailed;
use Modules\Billing\Models\Customer;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PurchaseEligibility;
use Saucebase\Core\Helpers\Toast;
use Saucebase\Core\Settings\SettingsSection;

class BillingPortalController
{
    public function __construct(
        private BillingService $billingService,
    ) {}

    public function __invoke(): RedirectResponse
    {
        $customer = Customer::where('user_id', Auth::id())->first();

        if (! $customer) {
            Toast::error(__('No billing account found. Please subscribe to a plan first.'));

            return redirect()->to(SettingsSection::url('billing'));
        }

        try {
            $url = $this->billingService->getManagementUrl(Auth::user());
        } catch (GatewayOperationFailed $e) {
            report($e);

            Toast::error(__('Billing management is not available right now. Please try again later.'));

            return redirect()->to(SettingsSection::url('billing'));
        }

        return redirect()->away($url);
    }

    /**
     * Only the signed-in user's own subscription: nothing in the request says
     * which one, so there is nobody else's to reach.
     */
    public function changePlan(PurchaseEligibility $eligibility): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $subscription = $user->billingCustomer?->currentSubscription();

        if (! $subscription || $eligibility->isReplacedByLifetime($subscription)) {
            abort(404);
        }

        try {
            return redirect()->away($this->billingService->getPlanChangeUrl($subscription));
        } catch (GatewayOperationFailed $e) {
            report($e);

            Toast::error(__('Plan changes are not available right now. Please try again later.'));

            return redirect()->to(SettingsSection::url('billing'));
        }
    }
}
