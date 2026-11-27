<?php

namespace Modules\Billing\Data;

use Carbon\Carbon;
use Spatie\LaravelData\Data;

/**
 * A promotion code as the provider describes it. `maxRedemptions` and
 * `expiresAt` are the tighter of the code's and its coupon's.
 *
 * @param  string  $duration  once, repeating or forever
 * @param  bool  $restricted  The provider limits who may redeem it (first-time customers,
 *                            a minimum amount, one customer): it decides at checkout
 */
class PromotionData extends Data
{
    public function __construct(
        public string $providerPromotionCodeId,
        public string $code,
        public ?float $percentOff,
        public ?int $amountOff,
        public ?string $currency,
        public string $duration,
        public ?int $durationInMonths,
        public ?int $maxRedemptions,
        public int $timesRedeemed,
        public ?Carbon $expiresAt,
        public bool $active,
        public bool $restricted = false,
    ) {}
}
