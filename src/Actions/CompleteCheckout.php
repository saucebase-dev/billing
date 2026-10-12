<?php

namespace Modules\Billing\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Data\Webhook\CheckoutSessionData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Events\CheckoutCompleted;
use Modules\Billing\Events\PaymentSucceeded;
use Modules\Billing\Events\SubscriptionCreated;
use Modules\Billing\Exceptions\GatewayOperationFailedException;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Services\PaymentGatewayManager;

/** Turn a paid checkout into its subscription or payment, once, whichever path reports it first. */
class CompleteCheckout
{
    public function __construct(
        private PaymentGatewayManager $manager,
        private SyncPaymentMethod $syncPaymentMethod,
        private CancelSubscription $cancelSubscription,
    ) {}

    public function handle(string $provider, CheckoutSessionData $checkout): void
    {
        // Locked and re-read inside the transaction: the return redirect and the
        // webhook both complete the same session, and only one may record it.
        $recorded = DB::transaction(function () use ($checkout, $provider): ?array {
            $session = CheckoutSession::where('provider', $provider)
                ->where('provider_session_id', $checkout->sessionId)
                ->lockForUpdate()
                ->first();

            if (! $session || $session->status === CheckoutSessionStatus::Completed) {
                return null;
            }

            $customer = $session->customer;

            if (! $customer) {
                Log::warning('Checkout session missing customer', ['session_id' => $session->id]);

                return null;
            }

            $session->update(['status' => CheckoutSessionStatus::Completed]);

            $paymentMethod = $checkout->paymentMethodReference
                ? $this->syncPaymentMethod->handle($customer, $checkout->paymentMethodReference, $provider)
                : null;

            $subscription = $checkout->providerSubscriptionId
                ? Subscription::firstOrCreate(
                    ['provider' => $provider, 'provider_subscription_id' => $checkout->providerSubscriptionId],
                    [
                        'customer_id' => $session->customer_id,
                        'price_id' => $session->price_id,
                        'payment_method_id' => $paymentMethod?->id,
                        'status' => SubscriptionStatus::Active,
                        'current_period_starts_at' => now(),
                    ],
                )
                : null;

            // A subscription's first payment, or a one-time purchase.
            $payment = $subscription || $checkout->providerPaymentId
                ? Payment::create([
                    'customer_id' => $session->customer_id,
                    'provider' => $provider,
                    'subscription_id' => $subscription?->id,
                    'price_id' => $session->price_id,
                    'payment_method_id' => $paymentMethod?->id,
                    'provider_payment_id' => $subscription ? null : $checkout->providerPaymentId,
                    'currency' => $checkout->currency,
                    'amount' => $checkout->amount,
                    'status' => PaymentStatus::Succeeded,
                ])
                : null;

            return [$session, $subscription, $payment];
        });

        $this->endSubscriptionReplacedByLifetime($provider, $checkout->sessionId);

        if ($recorded === null) {
            return;
        }

        [$session, $subscription, $payment] = $recorded;

        // After commit: a provider call has no place inside the transaction.
        if ($subscription?->wasRecentlyCreated) {
            $this->syncSubscriptionPeriod($subscription, $checkout->providerSubscriptionId, $provider);
        }

        if ($payment) {
            event(new PaymentSucceeded($payment));
        }

        if ($subscription) {
            event(new SubscriptionCreated($subscription));
        }

        event(new CheckoutCompleted($session));
    }

    private function syncSubscriptionPeriod(Subscription $subscription, string $providerSubscriptionId, string $provider): void
    {
        try {
            $remote = $this->manager->driver($provider)->retrieveSubscription($providerSubscriptionId);

            // Read at checkout rather than waiting for the first update event,
            // so a trial is on the row within a second of the buyer paying.
            $updates = array_filter([
                ...$remote->trialDates(),
                'current_period_starts_at' => $remote->periodStartsAt,
                'current_period_ends_at' => $remote->periodEndsAt,
            ]);

            if ($updates) {
                $subscription->update($updates);
            }
        } catch (GatewayOperationFailedException $e) {
            // The subscription's next event fills the dates in.
            report($e);
        }
    }

    /**
     * A subscriber who buys the lifetime plan replacing their subscription stops
     * paying for it, and keeps it until the period they already paid for ends.
     * A subscription to any other plan is left alone: lifetime covers its own
     * plan only.
     *
     * Runs on every delivery, not only the one that completed the session: it
     * calls the provider after commit, and if that fails the retry — which
     * finds the session already completed — must still get here.
     */
    private function endSubscriptionReplacedByLifetime(string $provider, string $providerSessionId): void
    {
        $session = CheckoutSession::where('provider', $provider)
            ->where('provider_session_id', $providerSessionId)
            ->where('status', CheckoutSessionStatus::Completed)
            ->first();

        $plan = $session?->price?->plan;

        if (! $session?->customer || $plan?->kind !== PlanKind::Lifetime || $plan->replaces_product_id === null) {
            return;
        }

        $subscription = $session->customer->currentSubscription();

        if ($subscription && $subscription->cancelled_at === null && $subscription->price?->product_id === $plan->replaces_product_id) {
            $this->cancelSubscription->handle($subscription);
        }
    }
}
