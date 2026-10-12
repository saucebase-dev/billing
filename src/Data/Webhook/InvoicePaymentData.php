<?php

namespace Modules\Billing\Data\Webhook;

use Modules\Billing\Enums\Currency;
use Spatie\LaravelData\Data;

/** An attempt to collect an invoice: succeeded or failed, per the event type. */
class InvoicePaymentData extends Data implements WebhookEventData
{
    public function __construct(
        public ?string $providerCustomerId,
        public ?string $providerSubscriptionId,
        public string $providerPaymentId,
        public ?string $paymentMethodReference,
        public Currency $currency,
        public int $amount,
    ) {}
}
