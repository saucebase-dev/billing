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
     * @param  ?string  $providerInvoiceId  A subscription's first invoice; its payment events name it too.
     * @param  int  $discountAmount  What the discount took off, in minor units.
     * @param  ?string  $providerPromotionCodeId  The promotion code that took it off, when there was one.
     */
    public function __construct(
        public string $sessionId,
        public bool $fulfillable,
        public ?string $providerSubscriptionId,
        public ?string $providerPaymentId,
        public ?string $paymentMethodReference,
        public Currency $currency,
        public int $amount,
        public ?string $providerInvoiceId = null,
        public int $discountAmount = 0,
        public ?string $providerPromotionCodeId = null,
    ) {}
}
