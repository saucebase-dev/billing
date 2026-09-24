<?php

namespace Modules\Billing\Data\Webhook;

use Spatie\LaravelData\Data;

class RefundData extends Data implements WebhookEventData
{
    public function __construct(
        public ?string $providerCustomerId,
        public ?string $providerPaymentId,
        public ?int $amountRefunded,
        public bool $fullyRefunded,
    ) {}
}
