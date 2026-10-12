<?php

namespace Modules\Billing\Data\Webhook;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/** The payment method a customer's invoices are charged to; `null` means cleared. */
class CustomerDefaultsData extends Data implements WebhookEventData
{
    public function __construct(
        public string $providerCustomerId,
        public string|null|Optional $defaultPaymentMethodReference = new Optional,
    ) {}
}
