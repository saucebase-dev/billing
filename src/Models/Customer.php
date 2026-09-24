<?php

namespace Modules\Billing\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\PlanKind;

/**
 * @property int $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property-read (BillingOwner&Model)|null $owner
 * @property string $provider
 * @property string|null $provider_customer_id
 * @property string|null $email
 * @property string|null $name
 * @property string|null $phone
 * @property array<string, mixed>|null $address
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'provider',
        'provider_customer_id',
        'email',
        'name',
        'phone',
        'address',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Null once the owner is deleted: the account and its history stay.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<PaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<CheckoutSession, $this>
     */
    public function checkoutSessions(): HasMany
    {
        return $this->hasMany(CheckoutSession::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The subscription the customer holds, access or not — ask `grantsAccess()`
     * for that. A customer holds at most one, which checkout enforces; the
     * newest wins should that ever break.
     */
    public function currentSubscription(): ?Subscription
    {
        return $this->subscriptions()->current()->latest('id')->first();
    }

    /**
     * Whether this customer has used their one trial, ever.
     *
     * Two things count: a subscription that has trialed, and a checkout still
     * holding a trial it was issued with. The second is what stops two open
     * checkouts from both being trials — history alone is written too late.
     */
    public function hasTrialed(): bool
    {
        return $this->subscriptions()->whereNotNull('trial_starts_at')->exists()
            || $this->checkoutSessions()
                ->whereIn('status', [CheckoutSessionStatus::Pending, CheckoutSessionStatus::Completed])
                ->where('trial_days', '>', 0)
                ->exists();
    }

    /**
     * The lifetime plans this customer owns: paid, unrefunded payments for a
     * price on a plan of kind Lifetime, newest first. There is no row of its own. Retired
     * plans still count — archiving stops new sales, not what was bought.
     *
     * @return Collection<int, Payment>
     */
    public function lifetimePurchases(): Collection
    {
        return $this->payments()
            ->whereNull('subscription_id')
            ->where('status', PaymentStatus::Succeeded)
            ->whereHas('price.plan', fn (Builder $plan) => $plan->where('kind', PlanKind::Lifetime))
            ->with('price.plan')
            ->latest('id')
            ->get();
    }
}
