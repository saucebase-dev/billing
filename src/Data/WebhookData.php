<?php

namespace Modules\Billing\Data;

use Carbon\CarbonImmutable;
use Modules\Billing\Data\Webhook\WebhookEventData;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Exceptions\InvalidWebhookData;
use Spatie\LaravelData\Data;

class WebhookData extends Data
{
    /**
     * @param  ?WebhookEventType  $type  Null for an event the module does not handle.
     * @param  ?WebhookEventData  $data  What the event says; null when it is unhandled.
     */
    public function __construct(
        public ?WebhookEventType $type,
        public string $provider,
        public string $providerEventId,
        public ?WebhookEventData $data = null,
        public ?CarbonImmutable $occurredAt = null,
    ) {}

    public function is(WebhookEventType $type): bool
    {
        return $this->type === $type;
    }

    /**
     * The event's data as the class the handler expects. A gateway that sends
     * the wrong shape fails here, loudly, rather than as a null further on.
     *
     * @template T of WebhookEventData
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function dataAs(string $class): WebhookEventData
    {
        if (! $this->data instanceof $class) {
            throw new InvalidWebhookData(
                provider: $this->provider,
                eventType: $this->type?->value,
                providerEventId: $this->providerEventId,
                expected: $class,
                actual: $this->data === null ? null : $this->data::class,
            );
        }

        return $this->data;
    }
}
