<?php

namespace Modules\Billing\Exceptions;

/** A delivery that does not prove it came from the provider. Never processed. */
class InvalidWebhookSignature extends BillingException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct("A {$provider} webhook failed signature verification.");
    }

    public function id(): string
    {
        return 'billing.invalid_webhook_signature';
    }

    protected function details(): array
    {
        return ['provider' => $this->provider];
    }
}
