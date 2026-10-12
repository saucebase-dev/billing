<?php

namespace Modules\Billing\Data\Webhook;

use Modules\Billing\Enums\Currency;
use Spatie\LaravelData\Data;

class CheckoutSessionData extends Data implements WebhookEventData
{
    /**
     * @param  bool  $fulfillable  Completed and either paid or validly owing nothing
     *                             (a trial, a full discount). Open, expired, failed or
     *                             not yet settled checkouts are not.
     * @param  ?string  $paymentMethodReference  Anything the gateway resolves to a
     *                                           payment method through `resolvePaymentMethod()`.
     */
    public function __construct(
        public string $sessionId,
        public bool $fulfillable,
        public ?string $providerSubscriptionId,
        public ?string $providerPaymentId,
        public ?string $paymentMethodReference,
        public Currency $currency,
        public int $amount,
    ) {}
}
