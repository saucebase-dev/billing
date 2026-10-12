<?php

namespace Modules\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Billing\Actions\CancelSubscription;
use Modules\Billing\Actions\ResumeSubscription;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Exceptions\GatewayOperationFailedException;
use Modules\Billing\Services\BillingOwners;
use Saucebase\Core\Toast;

class SubscriptionController
{
    public function __construct(
        private CancelSubscription $cancelSubscription,
        private BillingOwners $owners,
    ) {}

    public function cancel(Request $request): RedirectResponse
    {
        $subscription = $this->owners->managedBy($request->user())->billingAccount()?->currentSubscription();

        if (! $subscription) {
            abort(404);
        }

        try {
            $this->cancelSubscription->handle($subscription);
        } catch (GatewayOperationFailedException $e) {
            return $this->unavailable($e);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Your subscription will be cancelled at the end of the billing period.'),
        ]);
    }

    public function resume(Request $request, ResumeSubscription $resumeSubscription): RedirectResponse
    {
        $subscription = $this->owners->managedBy($request->user())->billingAccount()
            ?->subscriptions()
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('cancelled_at')
            ->latest()
            ->first();

        if (! $subscription) {
            abort(404);
        }

        try {
            $resumeSubscription->handle($subscription);
        } catch (GatewayOperationFailedException $e) {
            return $this->unavailable($e);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Your subscription has been resumed.'),
        ]);
    }

    /** The provider did not answer: nothing changed, so say so and let them try again. */
    private function unavailable(GatewayOperationFailedException $e): RedirectResponse
    {
        report($e);

        Toast::error(__('We could not reach the payment provider. Nothing was changed; please try again in a moment.'));

        return back();
    }
}
