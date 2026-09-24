<?php

namespace Modules\Billing\Exceptions;

/**
 * The subscription kept changing while the provider was asked about it. The
 * delivery fails so the provider sends it again; nothing was written.
 */
class SubscriptionReconciliationConflict extends BillingException
{
    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $attempts,
    ) {
        parent::__construct("Could not reconcile subscription {$subscriptionId}: it changed during each of {$attempts} reads.");
    }

    public function id(): string
    {
        return 'billing.subscription_reconciliation_conflict';
    }

    protected function details(): array
    {
        return ['subscription_id' => $this->subscriptionId, 'attempts' => $this->attempts];
    }
}
