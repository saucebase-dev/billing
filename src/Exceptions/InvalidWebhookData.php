<?php

namespace Modules\Billing\Exceptions;

/** A recognised event whose data is not the shape the module expects: a gateway defect. */
class InvalidWebhookData extends BillingException
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $eventType,
        public readonly string $providerEventId,
        public readonly string $expected,
        public readonly ?string $actual,
    ) {
        parent::__construct(sprintf('A %s event from %s carried %s, not %s.', $eventType ?? 'unhandled', $provider, $actual ?? 'no data', $expected));
    }

    public function id(): string
    {
        return 'billing.invalid_webhook_data';
    }

    protected function details(): array
    {
        return [
            'provider' => $this->provider,
            'event_type' => $this->eventType,
            'provider_event_id' => $this->providerEventId,
            'expected' => $this->expected,
            'actual' => $this->actual,
        ];
    }
}
