<?php

namespace Modules\Billing\Data;

use Spatie\LaravelData\Data;

/** A price as the provider describes it: the fields the provider owns and nothing else. */
class CatalogPriceData extends Data
{
    public function __construct(
        public string $providerPriceId,
        public string $currency,
        public int $amount,
        public ?string $interval,
        public ?int $intervalCount,
        public bool $active,
    ) {}
}
