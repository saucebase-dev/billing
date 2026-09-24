<?php

namespace Modules\Billing\Data\Webhook;

use Carbon\Carbon;
use Modules\Billing\Enums\Currency;
use Spatie\LaravelData\Data;

class InvoiceData extends Data implements WebhookEventData
{
    /**
     * @param  ?Carbon  $periodStartsAt  The span every line covers: proration lines cover only part of it.
     */
    public function __construct(
        public string $providerInvoiceId,
        public ?string $providerCustomerId,
        public ?string $providerSubscriptionId,
        public ?string $number,
        public Currency $currency,
        public int $subtotal,
        public int $tax,
        public int $total,
        public ?string $hostedUrl,
        public ?string $pdfUrl,
        public ?Carbon $periodStartsAt,
        public ?Carbon $periodEndsAt,
    ) {}
}
