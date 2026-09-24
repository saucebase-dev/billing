<?php

namespace Modules\Billing\Data\Webhook;

use Carbon\Carbon;
use Modules\Billing\Enums\SubscriptionStatus;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * A subscription as the provider sees it. `Optional` means the provider did not
 * mention a field, which leaves it alone; `null` means it cleared it.
 */
class SubscriptionStateData extends Data implements WebhookEventData
{
    /**
     * @param  ?SubscriptionStatus  $status  Null when the provider's status means nothing here.
     * @param  bool|Optional  $cancellationScheduled  Whether the subscription is set to end; `false` undoes a scheduled cancellation.
     * @param  ?Carbon  $endsAt  When a scheduled cancellation takes effect.
     */
    public function __construct(
        public string $providerSubscriptionId,
        public ?string $providerCustomerId,
        public ?SubscriptionStatus $status,
        public ?Carbon $trialStartsAt = null,
        public ?Carbon $trialEndsAt = null,
        public ?Carbon $periodStartsAt = null,
        public ?Carbon $periodEndsAt = null,
        public ?string $providerPriceId = null,
        public string|null|Optional $paymentMethodReference = new Optional,
        public bool|Optional $cancellationScheduled = new Optional,
        public ?Carbon $endsAt = null,
    ) {}
}
