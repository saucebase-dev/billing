<?php

namespace Modules\Billing\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Billing\Enums\CheckoutSessionStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $customer_id
 * @property int $price_id
 * @property string|null $provider
 * @property string|null $provider_session_id
 * @property string|null $provider_url
 * @property string|null $success_url
 * @property string|null $cancel_url
 * @property string|null $coupon
 * @property int|null $trial_days
 * @property bool|null $trial_requires_payment_method
 * @property CheckoutSessionStatus $status
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CheckoutSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'customer_id',
        'price_id',
        'provider',
        'provider_session_id',
        'provider_url',
        'success_url',
        'cancel_url',
        'status',
        'metadata',
        'expires_at',
        'coupon',
        'trial_days',
        'trial_requires_payment_method',
    ];

    protected static function booted(): void
    {
        static::creating(function (CheckoutSession $session) {
            if (! $session->uuid) {
                $session->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CheckoutSessionStatus::class,
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'trial_days' => 'integer',
            'trial_requires_payment_method' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Price, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(Price::class);
    }
}
