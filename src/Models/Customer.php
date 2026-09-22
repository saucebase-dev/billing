<?php

namespace Modules\Billing\Models;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\PlanKind;

/**
 * @property int $id
 * @property int|null $user_id
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
        'user_id',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The subscription that grants access now. A customer holds at most one,
     * which checkout enforces; the newest wins should that ever break.
     */
    public function currentSubscription(): ?Subscription
    {
        return $this->subscriptions()->current()->latest('id')->first();
    }

    /**
     * The lifetime plans this customer owns: paid, unrefunded payments for a
     * price on a plan of kind Lifetime. There is no row of its own. Retired
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
            ->get();
    }

}
