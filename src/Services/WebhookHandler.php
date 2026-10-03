<?php

namespace Modules\Billing\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Actions\CompleteCheckout;
use Modules\Billing\Actions\SyncPaymentMethod;
use Modules\Billing\Data\Webhook\CheckoutSessionData;
use Modules\Billing\Data\Webhook\CustomerDefaultsData;
use Modules\Billing\Data\Webhook\InvoiceData;
use Modules\Billing\Data\Webhook\InvoicePaymentData;
use Modules\Billing\Data\Webhook\PaymentMethodChangeData;
use Modules\Billing\Data\Webhook\RefundData;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\InvoiceStatus;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Events\InvoicePaid;
use Modules\Billing\Events\PaymentFailed;
use Modules\Billing\Events\PaymentSucceeded;
use Modules\Billing\Events\SubscriptionCancelled;
use Modules\Billing\Events\SubscriptionUpdated;
use Modules\Billing\Events\TrialEnding;
use Modules\Billing\Exceptions\SubscriptionReconciliationConflictException;
use Modules\Billing\Exceptions\WebhookDependencyNotReadyException;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\WebhookEvent;
use Spatie\LaravelData\Optional;

class WebhookHandler
{
    public function __construct(
        private PaymentGatewayManager $manager,
        private CompleteCheckout $completeCheckout,
        private SyncPaymentMethod $syncPaymentMethod,
    ) {}

    public function handle(string $provider, Request $request): void
    {
        $gateway = $this->manager->driver($provider);
        $webhook = $gateway->verifyAndParseWebhook($request);

        $webhookType = $webhook->type->value ?? 'unmapped';
        Log::info('Webhook received', ['provider' => $provider, 'type' => $webhookType]);

        $event = WebhookEvent::firstOrCreate(
            ['provider' => $provider, 'provider_event_id' => $webhook->providerEventId],
            ['type' => $webhookType],
        );

        // One UPDATE claims the event, so two deliveries cannot both run the
        // handler. A handler that throws gives the claim back for the retry.
        // ponytail: a hard crash mid-handler keeps the claim; add a started_at
        // timeout if that ever bites.
        $claimed = WebhookEvent::whereKey($event->id)->whereNull('processed_at')->update(['processed_at' => now()]);

        if ($claimed === 0) {
            Log::info('Webhook already processed, skipping', ['provider_event_id' => $webhook->providerEventId]);

            return;
        }

        try {
            $this->dispatch($webhook);
        } catch (\Throwable $e) {
            $event->update(['processed_at' => null]);

            throw $e;
        }
    }

    private function dispatch(WebhookData $webhook): void
    {
        match ($webhook->type) {
            WebhookEventType::CheckoutCompleted => $this->onCheckoutCompleted($webhook),
            WebhookEventType::SubscriptionUpdated => $this->onSubscriptionUpdated($webhook),
            WebhookEventType::SubscriptionDeleted => $this->onSubscriptionDeleted($webhook),
            WebhookEventType::SubscriptionTrialWillEnd => $this->onTrialWillEnd($webhook),
            WebhookEventType::PaymentSucceeded => $this->onPaymentSucceeded($webhook),
            WebhookEventType::PaymentFailed => $this->onPaymentFailed($webhook),
            WebhookEventType::InvoicePaid => $this->onInvoicePaid($webhook),
            WebhookEventType::PaymentRefunded => $this->onPaymentRefunded($webhook),
            WebhookEventType::PaymentMethodAttached => $this->onPaymentMethodAttached($webhook),
            WebhookEventType::PaymentMethodDetached => $this->onPaymentMethodDetached($webhook),
            WebhookEventType::CustomerUpdated => $this->onCustomerUpdated($webhook),
            default => null,
        };
    }

    private function customerFor(string $provider, ?string $providerCustomerId): ?Customer
    {
        // A null ID would match every customer the provider has never seen.
        if ($providerCustomerId === null || $providerCustomerId === '') {
            return null;
        }

        return Customer::where('provider', $provider)
            ->where('provider_customer_id', $providerCustomerId)
            ->first();
    }

    /**
     * The subscription an event is about, or null when the event concerns data
     * this app has no record of.
     *
     * A missing subscription under a customer this app knows is a delivery it
     * expects to catch up with — the creating event may still be in flight — so
     * it throws and lets the provider retry. When the customer is unknown too,
     * nothing here ever described that subscription: a foreign account, or a
     * database rebuilt without it. Retrying cannot change that, so the event is
     * acknowledged instead of failing forever.
     */
    private function subscriptionForEvent(WebhookData $webhook, SubscriptionStateData $state): ?Subscription
    {
        $providerSubscriptionId = $state->providerSubscriptionId;
        $subscription = $this->subscriptionFor($webhook, $providerSubscriptionId);

        if ($subscription) {
            return $subscription;
        }

        $context = [
            'provider' => $webhook->provider,
            'provider_subscription_id' => $providerSubscriptionId,
            'provider_customer_id' => $state->providerCustomerId,
        ];

        if (! $this->customerFor($webhook->provider, $state->providerCustomerId)) {
            Log::warning('Ignoring subscription event for an unknown customer', $context);

            return null;
        }

        throw new WebhookDependencyNotReadyException(
            provider: $webhook->provider,
            eventType: $webhook->type?->value,
            providerEventId: $webhook->providerEventId,
            missing: 'subscription',
            providerResourceId: $providerSubscriptionId,
        );
    }

    private function subscriptionFor(WebhookData $webhook, string $providerSubscriptionId): ?Subscription
    {
        return Subscription::where('provider', $webhook->provider)
            ->where('provider_subscription_id', $providerSubscriptionId)
            ->first();
    }

    /**
     * Write the event's changes only if it is still the newest thing known about
     * the subscription, deciding that under a row lock so two deliveries cannot
     * interleave. Providers deliver out of order, and never reactivate a
     * cancelled subscription, so an older event or one arriving after
     * cancellation describes a state the subscription has moved past.
     *
     * @param  array<string, mixed>  $updates
     * @return Subscription|null The updated row, or null when the event was skipped.
     */
    private function applyIfCurrent(Subscription $subscription, WebhookData $webhook, array $updates, ?SubscriptionStatus $status = null): ?Subscription
    {
        return DB::transaction(function () use ($subscription, $webhook, $updates, $status) {
            $current = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $stale = $webhook->occurredAt && $current->last_event_at && $webhook->occurredAt->lt($current->last_event_at);

            if ($stale || $current->status === SubscriptionStatus::Cancelled) {
                Log::info('Ignoring stale subscription event', ['subscription_id' => $current->id]);

                return null;
            }

            $current->moveTo($status, $updates);

            return $current;
        });
    }

    /** A card added in the provider's portal, which is where a trialing customer adds one. */
    private function onPaymentMethodAttached(WebhookData $webhook): void
    {
        $change = $webhook->dataAs(PaymentMethodChangeData::class);
        $customer = $this->customerFor($webhook->provider, $change->providerCustomerId);

        if ($customer && $change->paymentMethodReference) {
            // Attaching a card does not make it the one invoices are charged to.
            $this->syncPaymentMethod->handle($customer, $change->paymentMethodReference, $webhook->provider, makeDefault: false);
        }
    }

    /** The card invoices are charged to, which the customer may change or clear in the portal. */
    private function onCustomerUpdated(WebhookData $webhook): void
    {
        $defaults = $webhook->dataAs(CustomerDefaultsData::class);
        $customer = $this->customerFor($webhook->provider, $defaults->providerCustomerId);

        // Not mentioned is not cleared: only an explicit null removes the default.
        if (! $customer || $defaults->defaultPaymentMethodReference instanceof Optional) {
            return;
        }

        if ($defaults->defaultPaymentMethodReference !== null) {
            $this->syncPaymentMethod->handle($customer, $defaults->defaultPaymentMethodReference, $webhook->provider);

            return;
        }

        PaymentMethod::where('customer_id', $customer->id)->where('is_default', true)->update(['is_default' => false]);
    }

    /** Removed there too, and a subscription pointing at it is left without one. */
    private function onPaymentMethodDetached(WebhookData $webhook): void
    {
        $change = $webhook->dataAs(PaymentMethodChangeData::class);

        // Without an ID this would match every row missing one. Matched on the
        // method's own ID, so removal never waits on a provider lookup.
        if (! $change->providerPaymentMethodId) {
            return;
        }

        PaymentMethod::where('provider', $webhook->provider)
            ->where('provider_payment_method_id', $change->providerPaymentMethodId)
            ->each(fn (PaymentMethod $method) => $method->delete());
    }

    /** The provider warns before a trial converts; the customer hears it from us. */
    private function onTrialWillEnd(WebhookData $webhook): void
    {
        $state = $webhook->dataAs(SubscriptionStateData::class);
        $subscription = $this->subscriptionForEvent($webhook, $state);

        if (! $subscription) {
            return;
        }

        $subscription = $this->applyIfCurrent($subscription, $webhook, $state->trialDates());

        if ($subscription) {
            event(new TrialEnding($subscription));
        }
    }

    /**
     * Ask the provider what a delinquent subscription is now, and apply that.
     *
     * Invoice events are not ordered against each other, so a late payment for
     * an old invoice must not hand access back. The read is authoritative; if
     * the row changes while it is in flight the answer is about an older row, so
     * it is asked again. Twice overtaken, it throws: the delivery goes back to
     * the provider rather than leaving a paying customer suspended.
     */
    private function reconcileDelinquency(Subscription $subscription): void
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $subscription->refresh();

            if (! in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true)) {
                return;
            }

            $revision = $subscription->state_revision;
            // An unknown status leaves the row as it is.
            $status = $this->manager->driver($subscription->provider)
                ->retrieveSubscription($subscription->provider_subscription_id)->status ?? $subscription->status;

            $applied = DB::transaction(function () use ($subscription, $revision, $status): ?Subscription {
                $current = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

                if ($current->state_revision !== $revision) {
                    return null;
                }

                $current->moveTo($status);

                return $current;
            });

            if ($applied) {
                $applied->announceDelinquency();

                return;
            }
        }

        throw new SubscriptionReconciliationConflictException($subscription->id, attempts: 2);
    }

    private function createPaymentFromWebhook(WebhookData $webhook, PaymentStatus $status): ?Payment
    {
        $attempt = $webhook->dataAs(InvoicePaymentData::class);
        $customer = $this->customerFor($webhook->provider, $attempt->providerCustomerId);

        if (! $customer) {
            return null;
        }

        $subscriptionId = $attempt->providerSubscriptionId;
        $subscription = $subscriptionId ? $this->subscriptionFor($webhook, $subscriptionId) : null;

        // Its checkout event is still on the way. Failing here has the provider
        // deliver this one again once the subscription exists locally.
        if ($subscriptionId && ! $subscription) {
            throw new WebhookDependencyNotReadyException(
                provider: $webhook->provider,
                eventType: $webhook->type?->value,
                providerEventId: $webhook->providerEventId,
                missing: 'subscription',
                providerResourceId: $subscriptionId,
            );
        }

        $pm = $attempt->paymentMethodReference
            ? $this->syncPaymentMethod->handle($customer, $attempt->paymentMethodReference, $webhook->provider)
            : null;

        $providerPaymentId = $attempt->providerPaymentId;

        $attributes = [
            'customer_id' => $customer->id,
            'provider' => $webhook->provider,
            'subscription_id' => $subscription?->id,
            'price_id' => $subscription?->price_id,
            'provider_payment_id' => $providerPaymentId,
            'currency' => $attempt->currency,
            'amount' => $attempt->amount,
            'status' => $status,
        ];

        if ($pm) {
            $attributes['payment_method_id'] = $pm->id;
        }

        // The same invoice fails and later succeeds under one payment intent, and
        // checkout completion records the first payment before its intent is known.
        $payment = Payment::where('provider', $webhook->provider)->where('provider_payment_id', $providerPaymentId)->first()
            ?? ($subscription
                // Recent only: a placeholder whose intent never arrived would
                // otherwise be adopted by a later cycle's invoice, overwriting
                // one period's payment history with the next one's.
                ? Payment::where('subscription_id', $subscription->id)
                    ->whereNull('provider_payment_id')
                    ->where('created_at', '>=', now()->subMinutes(5))
                    ->latest('id')
                    ->first()
                : null);

        // A failure delivered after the success it preceded must not undo it.
        if ($payment?->status === PaymentStatus::Succeeded && $status === PaymentStatus::Failed) {
            Log::info('Ignoring failure for a payment that has since succeeded', ['payment_id' => $payment->id]);

            return null;
        }

        if ($payment) {
            $payment->update($attributes);

            return $payment;
        }

        return Payment::create($attributes);
    }

    private function onCheckoutCompleted(WebhookData $webhook): void
    {
        $checkout = $webhook->dataAs(CheckoutSessionData::class);

        // A delayed payment method has not settled; a second event says when it does.
        if (! $checkout->fulfillable) {
            Log::info('Checkout completed but not yet paid', ['session_id' => $checkout->sessionId]);

            return;
        }

        $this->completeCheckout->handle($webhook->provider, $checkout);
    }

    private function onSubscriptionUpdated(WebhookData $webhook): void
    {
        $state = $webhook->dataAs(SubscriptionStateData::class);
        $subscription = $this->subscriptionForEvent($webhook, $state);

        if (! $subscription) {
            return;
        }

        // The provider call happens before the lock below, so the row is never
        // held across a network round trip.
        $pm = is_string($state->paymentMethodReference)
            ? $this->syncPaymentMethod->handle($subscription->customer, $state->paymentMethodReference, $webhook->provider)
            : null;

        // An unknown status leaves the row as it is.
        $status = $state->status ?? $subscription->status;

        $updates = ['last_event_at' => $webhook->occurredAt, ...$state->trialDates()];

        if ($pm) {
            $updates['payment_method_id'] = $pm->id;
        } elseif ($state->paymentMethodReference === null) {
            // Cleared at the provider: invoices fall back to the customer's default.
            $updates['payment_method_id'] = null;
        }

        // A plan changed at the provider (its billing portal) arrives here.
        $priceId = $this->localPriceId($webhook->provider, $state->providerPriceId);

        if ($priceId) {
            $updates['price_id'] = $priceId;
        }

        if ($state->periodStartsAt) {
            $updates['current_period_starts_at'] = $state->periodStartsAt;
        }

        if ($state->periodEndsAt) {
            $updates['current_period_ends_at'] = $state->periodEndsAt;
        }

        if ($state->cancellationScheduled === true) {
            $updates['cancelled_at'] = now();
            $updates['ends_at'] = $state->endsAt;
        } elseif ($state->cancellationScheduled === false) {
            $updates['cancelled_at'] = null;
            $updates['ends_at'] = null;
        }

        $subscription = $this->applyIfCurrent($subscription, $webhook, $updates, $status);

        if (! $subscription) {
            return;
        }

        Log::info('Subscription updated', ['subscription_id' => $subscription->id, 'status' => $subscription->status->value]);

        $subscription->announceDelinquency();

        event(new SubscriptionUpdated($subscription));
    }

    /**
     * The local price the provider's price ID names. One the catalogue has not
     * pulled yet is logged and skipped rather than failed: retrying would not
     * make it appear, and the daily sync plus the next event put it right.
     */
    private function localPriceId(string $provider, ?string $providerPriceId): ?int
    {
        if (! $providerPriceId) {
            return null;
        }

        $priceId = Price::where('provider', $provider)->where('provider_price_id', $providerPriceId)->value('id');

        if (! $priceId) {
            Log::warning('Subscription moved to a price unknown here; run the catalog sync', [
                'provider_price_id' => $providerPriceId,
            ]);
        }

        return $priceId;
    }

    /**
     * Only a full refund ends what the payment bought; a partial one is a
     * goodwill gesture and keeps access.
     *
     * Providers do not deliver in order, so a refund can beat the checkout that
     * created its payment. For a customer known here that payment is still on
     * its way, and failing lets the provider retry once it has landed; dropping
     * the refund would leave the purchase granting access for good.
     */
    private function onPaymentRefunded(WebhookData $webhook): void
    {
        $refund = $webhook->dataAs(RefundData::class);
        $paymentIntent = $refund->providerPaymentId;

        // A refund that names no payment names nothing we record, and looking
        // up a null ID would match an unrelated row.
        if ($paymentIntent === null) {
            Log::info('Refund without a payment, acknowledged', ['provider_event_id' => $webhook->providerEventId]);

            return;
        }

        $payment = Payment::where('provider', $webhook->provider)
            ->where('provider_payment_id', $paymentIntent)
            ->first();

        if (! $payment) {
            if (! $this->customerFor($webhook->provider, $refund->providerCustomerId)) {
                Log::warning('Ignoring refund for an unknown customer', ['payment_intent' => $paymentIntent]);

                return;
            }

            throw new WebhookDependencyNotReadyException(
                provider: $webhook->provider,
                eventType: $webhook->type?->value,
                providerEventId: $webhook->providerEventId,
                missing: 'payment',
                providerResourceId: $paymentIntent,
            );
        }

        $payment->update(array_filter([
            'amount_refunded' => $refund->amountRefunded,
            'status' => $refund->fullyRefunded ? PaymentStatus::Refunded : null,
        ], fn ($value) => $value !== null));
    }

    private function onSubscriptionDeleted(WebhookData $webhook): void
    {
        $subscription = $this->subscriptionForEvent($webhook, $webhook->dataAs(SubscriptionStateData::class));

        if (! $subscription) {
            return;
        }

        $subscription = $this->applyIfCurrent($subscription, $webhook, [
            'cancelled_at' => now(),
            'ends_at' => now(),
            'last_event_at' => $webhook->occurredAt,
        ], SubscriptionStatus::Cancelled);

        if (! $subscription) {
            return;
        }

        Log::info('Subscription cancelled', ['subscription_id' => $subscription->id]);

        event(new SubscriptionCancelled($subscription));
    }

    private function onPaymentSucceeded(WebhookData $webhook): void
    {
        $payment = $this->createPaymentFromWebhook($webhook, PaymentStatus::Succeeded);

        if (! $payment) {
            return;
        }

        // Before the early return below: a first attempt that saved the payment
        // and then failed to reach the provider must be repaired by the retry.
        if ($payment->subscription) {
            $this->reconcileDelinquency($payment->subscription);
        }

        // Filling in the checkout-created payment's intent is not a new success;
        // its event already fired from the checkout.
        if (! $payment->wasRecentlyCreated && ! $payment->wasChanged('status')) {
            return;
        }

        event(new PaymentSucceeded($payment));
    }

    private function onPaymentFailed(WebhookData $webhook): void
    {
        $payment = $this->createPaymentFromWebhook($webhook, PaymentStatus::Failed);

        if (! $payment) {
            return;
        }

        // The subscription's own event says it fell behind, in a stream the
        // provider does order. Inferring it from an invoice would race with it.
        event(new PaymentFailed($payment));
    }

    private function onInvoicePaid(WebhookData $webhook): void
    {
        $paid = $webhook->dataAs(InvoiceData::class);
        $customer = $this->customerFor($webhook->provider, $paid->providerCustomerId);

        if (! $customer) {
            return;
        }

        $subscription = $paid->providerSubscriptionId ? $this->subscriptionFor($webhook, $paid->providerSubscriptionId) : null;

        $invoice = Invoice::updateOrCreate(
            ['provider' => $webhook->provider, 'provider_invoice_id' => $paid->providerInvoiceId],
            [
                'customer_id' => $customer->id,
                'subscription_id' => $subscription?->id,
                'number' => $paid->number,
                'currency' => $paid->currency,
                'subtotal' => $paid->subtotal,
                'tax' => $paid->tax,
                'total' => $paid->total,
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
                'hosted_invoice_url' => $paid->hostedUrl,
                'pdf_url' => $paid->pdfUrl,
            ],
        );

        if ($subscription && $paid->periodStartsAt && $paid->periodEndsAt) {
            $subscription->update([
                'current_period_starts_at' => $paid->periodStartsAt,
                'current_period_ends_at' => $paid->periodEndsAt,
            ]);
        }

        event(new InvoicePaid($invoice));
    }
}
