<?php

namespace Modules\Billing\Exceptions;

/**
 * An event about a known customer's subscription or payment that has not
 * arrived yet. Expected: providers do not order their events, and failing the
 * delivery is what makes them send it again once the missing row exists.
 */
class WebhookDependencyNotReady extends BillingException
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $eventType,
        public readonly string $providerEventId,
        public readonly string $missing,
        public readonly string $providerResourceId,
    ) {
        parent::__construct("The {$missing} {$providerResourceId} this {$provider} event needs has not arrived yet.");
    }

    public function id(): string
    {
        return 'billing.webhook_dependency_not_ready';
    }

    protected function details(): array
    {
        return [
            'provider' => $this->provider,
            'event_type' => $this->eventType,
            'provider_event_id' => $this->providerEventId,
            'missing' => $this->missing,
            'provider_resource_id' => $this->providerResourceId,
        ];
    }
}
