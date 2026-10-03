<?php

namespace Modules\Billing\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Billing\Events\SubscriptionResumed;
use Modules\Billing\Exceptions\GatewayOperationFailedException;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Services\PurchaseEligibility;

/** Undo a pending cancellation, through the gateway the subscription was bought on. */
class ResumeSubscription
{
    public function __construct(
        private PaymentGatewayManager $gateways,
        private PurchaseEligibility $eligibility,
    ) {}

    /**
     * @throws ValidationException when a lifetime plan replaces it
     * @throws GatewayOperationFailedException when the provider does not answer
     */
    public function handle(Subscription $subscription): void
    {
        if ($this->eligibility->isReplacedByLifetime($subscription)) {
            throw ValidationException::withMessages([
                'subscription' => __('Your lifetime plan replaces this subscription, so it ends as scheduled.'),
            ]);
        }

        $this->gateways->driver($subscription->provider)->resumeSubscription($subscription);

        $subscription->update([
            'cancelled_at' => null,
            'ends_at' => null,
        ]);

        SubscriptionResumed::dispatch($subscription);
    }
}
