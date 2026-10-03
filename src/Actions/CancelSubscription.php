<?php

namespace Modules\Billing\Actions;

use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\PaymentGatewayManager;

class CancelSubscription
{
    public function __construct(
        private PaymentGatewayManager $manager,
    ) {}

    /**
     * Stop renewal and keep access until the end of the period already paid for.
     * The local row is written straight away rather than left to the webhook,
     * so the page the customer returns to already shows the end date.
     */
    public function handle(Subscription $subscription): void
    {
        $periodEnd = $this->manager->driver($subscription->provider)->cancelSubscription($subscription);
        $endsAt = $periodEnd ?? $subscription->current_period_ends_at;

        $subscription->update([
            'cancelled_at' => now(),
            'ends_at' => $endsAt,
            'current_period_ends_at' => $endsAt,
        ]);
    }
}
