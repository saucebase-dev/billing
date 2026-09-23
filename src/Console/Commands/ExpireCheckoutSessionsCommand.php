<?php

namespace Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Settings\BillingSettings;

class ExpireCheckoutSessionsCommand extends Command
{
    /**
     * How long the provider remembers an idempotency key. Past it, replaying the
     * original request proves nothing about what it did the first time.
     */
    private const REPLAY_WINDOW_HOURS = 24;

    protected $signature = 'billing:expire-checkout-sessions';

    protected $description = 'Mark expired pending checkout sessions as expired';

    public function handle(PaymentGatewayManager $manager): int
    {
        $abandonedBefore = now()->subMinutes(app(BillingSettings::class)->checkout_abandon_after_minutes);

        $expired = $this->close(
            CheckoutSession::where('status', CheckoutSessionStatus::Pending)->where('expires_at', '<', now()),
            CheckoutSessionStatus::Expired,
            $manager,
        );

        $abandoned = $this->close(
            CheckoutSession::where('status', CheckoutSessionStatus::Pending)
                ->where('created_at', '<', $abandonedBefore)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            CheckoutSessionStatus::Abandoned,
            $manager,
        );

        $this->info("Marked {$expired} session(s) as expired, {$abandoned} as abandoned.");

        return self::SUCCESS;
    }

    /**
     * Close what can be closed, and leave the rest Pending.
     *
     * A session holding a trial is only closed once the provider confirms its
     * hosted page can no longer be paid — otherwise the customer could pay for
     * this checkout and start a second trial.
     *
     * @param  Builder<CheckoutSession>  $query
     */
    private function close(Builder $query, CheckoutSessionStatus $status, PaymentGatewayManager $manager): int
    {
        // One statement, so a checkout completing meanwhile is not overwritten.
        $closed = $query->clone()->where(fn ($q) => $q->whereNull('trial_days')->orWhere('trial_days', 0))
            ->update(['status' => $status]);

        $query->clone()->where('trial_days', '>', 0)->lazyById()->each(function (CheckoutSession $session) use ($status, $manager, &$closed): void {
            if (! $session->provider_session_id) {
                // Kept once found: the replay only works for a day, the expiry for ever.
                $session->update(['provider_session_id' => $this->resolveAtProvider($session, $manager)]);
            }

            $providerSessionId = $session->provider_session_id;

            if (! $providerSessionId || ! $manager->driver($session->provider ?? $manager->getDefaultDriver())->expireCheckoutSession($providerSessionId)) {
                Log::info('Keeping a checkout open: its trial cannot be released yet', ['checkout_session_id' => $session->id]);

                return;
            }

            // The same lock the grant takes, so a release cannot interleave with one.
            $closed += DB::transaction(function () use ($session, $status): int {
                Customer::whereKey($session->customer_id)->lockForUpdate()->first();

                return CheckoutSession::whereKey($session->id)
                    ->where('status', CheckoutSessionStatus::Pending)
                    ->update(['status' => $status]);
            });
        });

        return $closed;
    }

    /**
     * Find the provider's session for a hand-off that crashed before its ID was
     * stored, by replaying the original request under the same idempotency key.
     * The provider answers with the session it already made, if it made one.
     */
    private function resolveAtProvider(CheckoutSession $session, PaymentGatewayManager $manager): ?string
    {
        if ($session->created_at->lt(now()->subHours(self::REPLAY_WINDOW_HOURS))) {
            return null;
        }

        $session->loadMissing(['customer', 'price']);

        // Its customer can be deleted from under it; its price cannot.
        if (! $session->customer) {
            return null;
        }

        try {
            return $manager->driver($session->provider ?? $manager->getDefaultDriver())->createCheckoutSession(new CheckoutData(
                customer: $session->customer,
                price: $session->price,
                successUrl: $session->success_url,
                cancelUrl: $session->cancel_url,
                coupon: $session->coupon,
                trialDays: $session->trial_days,
                trialRequiresPaymentMethod: $session->trial_requires_payment_method,
                idempotencyKey: 'checkout_'.$session->uuid,
            ))->sessionId;
        } catch (\Throwable $e) {
            Log::warning('Could not resolve a checkout at the provider', [
                'checkout_session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
