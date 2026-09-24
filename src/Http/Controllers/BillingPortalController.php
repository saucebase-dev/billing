<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Billing\Exceptions\GatewayOperationFailed;
use Modules\Billing\Services\BillingOwners;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PurchaseEligibility;
use Saucebase\Core\Helpers\Toast;
use Saucebase\Core\Settings\SettingsSection;

class BillingPortalController
{
    public function __construct(
        private BillingService $billingService,
        private BillingOwners $owners,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $customer = $this->owners->managedBy($request->user())->billingAccount();

        if (! $customer) {
            Toast::error(__('No billing account found. Please subscribe to a plan first.'));

            return redirect()->to(SettingsSection::url('billing'));
        }

        try {
            $url = $this->billingService->getManagementUrl($customer);
        } catch (GatewayOperationFailed $e) {
            report($e);

            Toast::error(__('Billing management is not available right now. Please try again later.'));

            return redirect()->to(SettingsSection::url('billing'));
        }

        return redirect()->away($url);
    }

    /**
     * Only the subscription of the owner the user manages: nothing in the
     * request says which one, so there is nobody else's to reach.
     */
    public function changePlan(Request $request, PurchaseEligibility $eligibility): RedirectResponse
    {
        $subscription = $this->owners->managedBy($request->user())->billingAccount()?->currentSubscription();

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
