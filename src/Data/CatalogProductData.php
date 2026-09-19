<?php

namespace Modules\Billing\Data;

use Spatie\LaravelData\Data;

class CatalogProductData extends Data
{
    /**
     * @param  list<CatalogPriceData>  $prices
     * @param  list<string>  $features
     */
    public function __construct(
        public string $providerProductId,
        public string $name,
        public ?string $description,
        public bool $active,
        public array $prices,
        public array $features = [],
    ) {}
}
