<?php

namespace Modules\Billing\Data;

class CatalogPushReport
{
    public int $products = 0;

    public int $prices = 0;

    public int $features = 0;

    public int $promotions = 0;

    /** @var list<string> A code the provider has with other terms, or a promotion that takes nothing off a price. */
    public array $promotionWarnings = [];
}
