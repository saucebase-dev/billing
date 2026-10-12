<?php

namespace Modules\Billing\Data\Webhook;

use Spatie\LaravelData\Data;

/**
 * A payment method added or removed at the provider. Removal matches on
 * `providerPaymentMethodId` alone, so it never depends on a provider lookup.
 */
class PaymentMethodChangeData extends Data implements WebhookEventData
{
    public function __construct(
        public ?string $providerPaymentMethodId,
        public ?string $paymentMethodReference,
        public ?string $providerCustomerId,
    ) {}
}
