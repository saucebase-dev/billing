<?php

namespace Modules\Billing\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Billing\Data\PromotionData;

/**
 * A provider's promotion code that the pricing page shows and checkout applies.
 *
 * The provider enforces it (cap, end date, who may redeem); this row is what the
 * page reads. `times_redeemed` is counted up by the checkout webhook and set
 * back to the provider's figure by `billing:sync-catalog`.
 *
 * @property int $id
 * @property string $provider
 * @property string $provider_promotion_code_id
 * @property string $code
 * @property float|null $percent_off
 * @property int|null $amount_off
 * @property string|null $currency
 * @property string $duration once, repeating or forever
 * @property int|null $duration_in_months
 * @property int|null $max_redemptions
 * @property int $times_redeemed
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property-read int|null $remaining
 *
 * @method static Builder<static> available()
 */
class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'provider_promotion_code_id',
        'code',
        'percent_off',
        'amount_off',
        'currency',
        'duration',
        'duration_in_months',
        'max_redemptions',
        'times_redeemed',
        'expires_at',
        'is_active',
    ];

    /** The pricing page's view; the provider's ID stays here. */
    protected $visible = [
        'code',
        'percent_off',
        'amount_off',
        'currency',
        'duration',
        'duration_in_months',
        'max_redemptions',
        'remaining',
        'expires_at',
    ];

    protected $appends = ['remaining'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'percent_off' => 'float',
            'amount_off' => 'integer',
            'duration_in_months' => 'integer',
            'max_redemptions' => 'integer',
            'times_redeemed' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Active at the provider, not past its date, and not used up as far as this app has counted. */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q) => $q->whereNull('max_redemptions')->orWhereColumn('times_redeemed', '<', 'max_redemptions'));
    }

    /** @return Attribute<int<0, max>|null, never> */
    protected function remaining(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->max_redemptions === null
            ? null
            : max(0, $this->max_redemptions - $this->times_redeemed));
    }

    /**
     * What the price costs with this promotion, in minor units. Null when it does
     * not apply: an amount off in another currency takes nothing off this one.
     */
    public function discountedAmount(Price $price): ?int
    {
        if ($this->percent_off !== null) {
            return (int) round($price->amount * (100 - $this->percent_off) / 100);
        }

        if ($this->amount_off !== null && strcasecmp((string) $this->currency, $price->currency->value) === 0) {
            return max(0, $price->amount - $this->amount_off);
        }

        return null;
    }

    /** The provider's terms, as the gateway read them. The local count follows the provider's. */
    public function fillFromProvider(PromotionData $data): static
    {
        return $this->fill([
            'code' => $data->code,
            'percent_off' => $data->percentOff,
            'amount_off' => $data->amountOff,
            'currency' => $data->currency,
            'duration' => $data->duration,
            'duration_in_months' => $data->durationInMonths,
            'max_redemptions' => $data->maxRedemptions,
            'times_redeemed' => $data->timesRedeemed,
            'expires_at' => $data->expiresAt,
            'is_active' => $data->active,
        ]);
    }

    /** @return HasMany<Price, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
