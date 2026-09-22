<?php

namespace Modules\Billing\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Billing\Enums\BillingScheme;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Settings\BillingSettings;

/**
 * @property int $id
 * @property int $product_id
 * @property string $provider
 * @property string|null $provider_price_id
 * @property Currency $currency
 * @property int $amount
 * @property BillingScheme $billing_scheme
 * @property string|null $interval
 * @property int|null $interval_count
 * @property array<string, mixed>|null $metadata
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static> purchasable()
 */
class Price extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Admin-created prices are for the configured gateway unless told otherwise.
        static::creating(function (Price $price): void {
            $price->provider ??= app(BillingSettings::class)->gateway;
        });

        // The provider's prices are immutable and subscriptions bill on them, so
        // a price it knows is archived there and pulled, never deleted here. The
        // form hides the delete control; this is what a forged request meets.
        static::deleting(function (Price $price): void {
            if ($price->provider_price_id !== null) {
                throw new \RuntimeException("Price {$price->id} belongs to {$price->provider}; archive it there and sync.");
            }
        });
    }

    protected $fillable = [
        'product_id',
        'provider',
        'provider_price_id',
        'currency',
        'amount',
        'billing_scheme',
        'interval',
        'interval_count',
        'metadata',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'interval_count' => 'integer',
            'currency' => Currency::class,
            'billing_scheme' => BillingScheme::class,
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The provider owns the amount, currency and interval of a price it knows
     * about; the admin only reads them. A price with no provider ID is the
     * app's own (demo, test) and stays editable.
     */
    public function isManagedByGateway(): bool
    {
        return $this->provider_price_id !== null;
    }

    /**
     * A price somebody may buy right now: active itself, known to the provider,
     * and on a product that is active and not deleted. Hidden products stay
     * purchasable so a private link can still sell them.
     *
     * A price drafted here and never pushed has no provider ID, and the
     * provider rejects a checkout that names an empty one.
     */
    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('provider_price_id')
            ->whereHas('product', fn (Builder $product) => $product->where('is_active', true));
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The product as a plan someone holds, retired or not. Use this, not
     * `product()`, when deciding what a purchase grants: archiving a plan stops
     * new sales and must not reach back into what was bought.
     *
     * @return BelongsTo<Product, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }
}
