<?php

namespace Modules\Billing\Models;

use Carbon\Carbon;
use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Data\Entitlements;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\PlanKind;

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
 * @property PlanKind $kind
 * @property array<string, mixed>|null $entitlements
 * @property int|null $replaces_product_id
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

        static::saving(fn (Product $product) => $product->assertValid());

        // Soft or hard: the free plan is everyone's baseline, edited in place.
        static::deleting(function (Product $product): void {
            if ($product->kind === PlanKind::Free) {
                throw ValidationException::withMessages(['kind' => __('The free plan cannot be deleted; edit it instead.')]);
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
        'kind',
        'entitlements',
        'replaces_product_id',
    ];

    /** Fields buyers paid under: frozen while the plan is being sold or has been. */
    public const LOCKED_WHEN_SOLD = ['kind', 'slug', 'replaces_product_id'];

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
            'kind' => PlanKind::class,
            'entitlements' => 'array',
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

    public function entitlements(): Entitlements
    {
        /** @var array{features?: array<string, bool>, limits?: array<string, int|null>}|null $stored */
        $stored = $this->entitlements;

        return Entitlements::fromArray($stored);
    }

    /**
     * Whether anyone is paying, or has paid, under this plan's terms: a checkout
     * still open or completed, a payment, or a subscription on any of its prices.
     * An expired or abandoned checkout never reached a payment, so it does not count.
     */
    public function isSold(): bool
    {
        $priceIds = $this->prices()->select('id');

        return CheckoutSession::whereIn('price_id', $priceIds)
            ->whereIn('status', [CheckoutSessionStatus::Pending, CheckoutSessionStatus::Completed])
            ->exists()
            || Payment::whereIn('price_id', $priceIds)->exists()
            || Subscription::whereIn('price_id', $priceIds)->exists();
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_product_id');
    }

    /**
     * The rules a plan keeps however it is saved — admin form, catalog import or
     * code — since purchases and access are decided from them.
     *
     * @throws ValidationException
     */
    private function assertValid(): void
    {
        $kind = $this->kind ?? PlanKind::Subscription;

        if ($this->replaces_product_id !== null) {
            $target = self::withTrashed()->find($this->replaces_product_id);

            if ($kind !== PlanKind::Lifetime || $this->replaces_product_id === $this->id || $target?->kind !== PlanKind::Subscription) {
                throw ValidationException::withMessages([
                    'replaces_product_id' => __('Only a lifetime plan replaces another, and only a subscription plan other than itself.'),
                ]);
            }
        }

        Validator::make(['entitlements' => $this->entitlements ?? []], [
            'entitlements' => ['array:features,limits'],
            'entitlements.features' => ['array'],
            'entitlements.features.*' => ['accepted'],
            'entitlements.limits' => ['array'],
            'entitlements.limits.*' => ['nullable', 'integer', 'min:0'],
        ])->after(function ($validator): void {
            foreach (['features', 'limits'] as $group) {
                foreach (array_keys($this->entitlements[$group] ?? []) as $key) {
                    if (! preg_match('/^[a-z][a-z0-9_]*$/', (string) $key)) {
                        $validator->errors()->add('entitlements', __('Entitlement keys are snake_case, like max_projects.'));
                    }
                }
            }
        })->validate();

        if ($this->exists && $this->isDirty(self::LOCKED_WHEN_SOLD) && $this->isSold()) {
            throw ValidationException::withMessages([
                'kind' => __('This plan has been sold, so its kind, slug and replacement are fixed. Create a new plan instead.'),
            ]);
        }
    }

    /**
     * @return HasMany<Price, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }
}
