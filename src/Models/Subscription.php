<?php

namespace Modules\Billing\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Events\AccessSuspended;
use Modules\Billing\Events\GraceStarted;
use Modules\Billing\Settings\BillingSettings;

/**
 * @property int $id
 * @property int $customer_id
 * @property int $price_id
 * @property int|null $payment_method_id
 * @property string $provider
 * @property string|null $provider_subscription_id
 * @property SubscriptionStatus $status
 * @property Carbon|null $trial_starts_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_starts_at
 * @property Carbon|null $current_period_ends_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $grace_ends_at
 * @property int $state_revision
 * @property Carbon|null $ends_at
 * @property Carbon|null $last_event_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<Subscription> live()
 */
class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'price_id',
        'payment_method_id',
        'provider',
        'provider_subscription_id',
        'status',
        'trial_starts_at',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancelled_at',
        'grace_ends_at',
        'state_revision',
        'ends_at',
        'last_event_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'state_revision' => 'integer',
            'ends_at' => 'datetime',
            'last_event_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Everything the customer still holds with us, whether or not it grants
     * access now: that is `grantsAccess()`.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Suspended]);
    }

    /**
     * Whether the provider has something to charge: this subscription's card,
     * else the customer's default. A trial without one is cancelled when it ends.
     */
    public function hasPaymentMethod(): bool
    {
        return $this->payment_method_id !== null
            || $this->customer->paymentMethods()->where('is_default', true)->exists();
    }

    /**
     * Active grants access, a trial included: the provider reports a trial as
     * active. Behind on payment grants it until the grace deadline passes, and a
     * missing deadline grants nothing — it means a writer skipped the rule.
     */
    public function grantsAccess(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::Active => true,
            SubscriptionStatus::PastDue => $this->grace_ends_at?->isFuture() ?? false,
            default => false,
        };
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

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Write a row locked by the caller, moving it to `$status` under the
     * delinquency rules when one is given. A status change bumps the revision,
     * so a provider read taken before it can tell it is about an older row.
     *
     * @param  array<string, mixed>  $alsoUpdate
     */
    public function moveTo(?SubscriptionStatus $status, array $alsoUpdate = []): void
    {
        $delinquency = $status ? $this->delinquencyUpdates($status) : [];

        $this->update($delinquency === []
            ? $alsoUpdate
            : [...$alsoUpdate, ...$delinquency, 'state_revision' => $this->state_revision + 1]);
    }

    /**
     * Tell the customer what just happened to their subscription.
     *
     * Both mails hang off a transition rather than an event type, so a provider
     * repeating itself says nothing twice. They are best-effort: a crash between
     * the committed change and the queued job loses one.
     */
    public function announceDelinquency(): void
    {
        if (
            $this->wasChanged('status')
            && $this->status === SubscriptionStatus::Suspended
        ) {
            event(new AccessSuspended($this));

            return;
        }

        if (
            $this->wasChanged('grace_ends_at')
            && $this->status === SubscriptionStatus::PastDue
        ) {
            event(new GraceStarted($this));
        }
    }

    /**
     * One failed payment opens one episode with one deadline.
     *
     * The deadline is set once and never extended, so a second failure cannot buy
     * another window; a suspension pulls it back to now; and a suspension only
     * ends with a recovery or a cancellation, never with another failure.
     *
     * @return array<string, mixed>
     */
    private function delinquencyUpdates(SubscriptionStatus $status): array
    {
        if (
            $this->status === SubscriptionStatus::Suspended
            && $status === SubscriptionStatus::PastDue
        ) {
            return [];
        }

        if ($status === SubscriptionStatus::PastDue) {
            $deadline = $this->grace_ends_at
                ?? now()->addDays(app(BillingSettings::class)->grace_period_days);

            // A window of zero days, or one that closed while nobody was looking,
            // is a suspension rather than a grace period.
            return [
                'status' => $deadline->isFuture()
                    ? SubscriptionStatus::PastDue
                    : SubscriptionStatus::Suspended,
                'grace_ends_at' => $deadline,
            ];
        }

        if ($status === SubscriptionStatus::Suspended) {
            // A deadline already passed stays; one still ahead is pulled back to now.
            return [
                'status' => $status,
                'grace_ends_at' => $this->grace_ends_at?->isPast()
                    ? $this->grace_ends_at
                    : now(),
            ];
        }

        // Active (a trial included), pending or cancelled: the episode is over.
        return ['status' => $status, 'grace_ends_at' => null];
    }
}
