<?php

namespace Modules\Billing\Tests\Support;

use Carbon\CarbonImmutable;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Services\Gateways\StripeEventMapper;

/**
 * A Stripe delivery as the gateway would hand it on: Stripe's own payload shape,
 * through the real mapper. Tests built on it cover mapper and service together;
 * signature verification and Stripe itself are not in the loop.
 */
class StripeWebhook
{
    /** @param  array<string, mixed>  $payload  The event's `data.object`. */
    public static function make(?WebhookEventType $type, string $provider, string $providerEventId, array $payload, ?CarbonImmutable $occurredAt = null): WebhookData
    {
        return new WebhookData(
            type: $type,
            provider: $provider,
            providerEventId: $providerEventId,
            data: $type ? StripeEventMapper::map($type, self::withDefaults($type, $payload)) : null,
            occurredAt: $occurredAt,
        );
    }

    /**
     * What Stripe always sends and most fixtures leave out: a completed checkout
     * says whether it is paid. A fixture that cares sets it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function withDefaults(WebhookEventType $type, array $payload): array
    {
        return $type === WebhookEventType::CheckoutCompleted ? ['payment_status' => 'paid', ...$payload] : $payload;
    }
}
