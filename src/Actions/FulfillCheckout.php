<?php

namespace Modules\Billing\Actions;

use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Exceptions\GatewayOperationFailedException;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Services\PaymentGatewayManager;

class FulfillCheckout
{
    public function __construct(
        private PaymentGatewayManager $manager,
        private CompleteCheckout $completeCheckout,
    ) {}

    /**
     * Complete a checkout the buyer has returned from, in case its webhook has
     * not arrived. The caller has already scoped the session to its owner.
     *
     * @return bool Whether the checkout is paid — including when the webhook got
     *              here first, which is what tells the caller to congratulate.
     */
    public function handle(CheckoutSession $session): bool
    {
        if ($session->status === CheckoutSessionStatus::Completed) {
            return true;
        }

        if (! $session->provider || ! $session->provider_session_id) {
            return false;
        }

        try {
            $checkout = $this->manager->driver($session->provider)->retrieveCheckoutSession($session->provider_session_id);
        } catch (GatewayOperationFailedException $e) {
            // The panel still renders and nothing claims success; the webhook
            // completes the checkout when it arrives.
            report($e);

            return false;
        }

        if (! $checkout->fulfillable) {
            return false;
        }

        $this->completeCheckout->handle($session->provider, $checkout);

        return true;
    }
}
