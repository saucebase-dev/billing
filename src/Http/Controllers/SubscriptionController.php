<?php

namespace Modules\Billing\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Events\SubscriptionResumed;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PurchaseEligibility;

class SubscriptionController
{
    public function __construct(
        private BillingService $billingService,
    ) {}

    public function cancel(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $subscription = $user->billingCustomer?->currentSubscription();

        if (! $subscription) {
            abort(404);
        }

        $this->billingService->cancelAtPeriodEnd($subscription);

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Your subscription will be cancelled at the end of the billing period.'),
        ]);
    }

    public function resume(PurchaseEligibility $eligibility): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $subscription = $user->billingCustomer
            ?->subscriptions()
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('cancelled_at')
            ->latest()
            ->first();

        if (! $subscription) {
            abort(404);
        }

        if ($eligibility->isReplacedByLifetime($subscription)) {
            throw ValidationException::withMessages([
                'subscription' => __('Your lifetime plan replaces this subscription, so it ends as scheduled.'),
            ]);
        }

        $this->billingService->resume($subscription);

        $subscription->update([
            'cancelled_at' => null,
            'ends_at' => null,
        ]);

        SubscriptionResumed::dispatch($subscription);

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Your subscription has been resumed.'),
        ]);
    }
}
