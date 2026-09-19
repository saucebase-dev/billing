<?php

namespace Modules\Billing\Data;

use Carbon\CarbonImmutable;
use Modules\Billing\Enums\WebhookEventType;
use Spatie\LaravelData\Data;

class WebhookData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ?WebhookEventType $type,
        public string $provider,
        public string $providerEventId,
        public array $payload,
        public ?CarbonImmutable $occurredAt = null,
    ) {}

    public function is(WebhookEventType $type): bool
    {
        return $this->type === $type;
    }
}
