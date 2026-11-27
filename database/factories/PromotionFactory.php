<?php

namespace Modules\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Billing\Models\Promotion;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'stripe',
            'provider_promotion_code_id' => 'promo_'.fake()->regexify('[A-Za-z0-9]{24}'),
            'code' => strtoupper(fake()->unique()->bothify('LAUNCH-####')),
            'percent_off' => null,
            'amount_off' => 1000,
            'currency' => 'USD',
            'duration' => 'once',
            'duration_in_months' => null,
            'max_redemptions' => null,
            'times_redeemed' => 0,
            'expires_at' => null,
            'is_active' => true,
        ];
    }
}
