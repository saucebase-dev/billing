<?php

namespace Modules\Billing\Models;

use Carbon\Carbon;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $sku
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $provider
 * @property string|null $provider_product_id
 * @property int $display_order
 * @property bool $is_visible
 * @property bool $is_highlighted
 * @property array<string, mixed>|null $features
 * @property array<string, mixed>|null $metadata
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder active()
 * @method static \Illuminate\Database\Eloquent\Builder visible()
 * @method static \Illuminate\Database\Eloquent\Builder displayable()
 */
class Product extends Model
{
    use HasFactory, Sluggable, SoftDeletes;

    protected static function booted(): void
    {
        // Prices cascade from here in the database, which does not fire their own
        // deleting guard, so a force delete would silently take prices the
        // provider still knows. Refused for the same reason they are.
        static::forceDeleting(function (Product $product): void {
            $known = $product->prices()->whereNotNull('provider_price_id')->first();

            if ($known) {
                throw new \RuntimeException("Product {$product->id} has prices at {$known->provider}; archive it there and sync.");
            }
        });

        static::addGlobalScope('ordered', function ($query) {
            // Insertion order breaks the tie: rows sharing a display_order would
            // otherwise come back in whatever order the database felt like, and
            // a pricing page that reshuffles between requests reads as a bug.
            $query->orderBy('display_order', 'asc')->orderBy('id', 'asc');
        });
    }

    protected $fillable = [
        'sku',
        'slug',
        'name',
        'description',
        'provider',
        'provider_product_id',
        'display_order',
        'is_visible',
        'is_highlighted',
        'features',
        'metadata',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'features' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'is_highlighted' => 'boolean',
            'display_order' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Return the sluggable configuration array for this model.
     */
    public function sluggable(): array
    {
        return [
            'slug' => [
                'source' => 'name',
            ],
            'sku' => [
                'source' => 'name',
                'separator' => '_',
            ],
        ];
    }

    /**
     * Scope a query to only include active products.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include visible products.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /**
     * Scope a query to the plans on the pricing page, with their active prices.
     * A price the provider does not know yet is listed too, and the page turns
     * its button off: checkout still refuses it through `purchasable()`.
     */
    public function scopeDisplayable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_visible', true)
            ->with(['prices' => fn ($prices) => $prices->where('is_active', true)]);
    }

    /**
     * @return HasMany<Price, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
