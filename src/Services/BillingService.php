<?php

namespace Modules\Billing\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Data\AddressData;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Data\CustomerData;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\InvoiceStatus;
use Modules\Billing\Enums\PaymentStatus;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Events\AccessSuspended;
use Modules\Billing\Events\CheckoutCompleted;
use Modules\Billing\Events\GraceStarted;
use Modules\Billing\Events\InvoicePaid;
use Modules\Billing\Events\PaymentFailed;
use Modules\Billing\Events\PaymentSucceeded;
use Modules\Billing\Events\SubscriptionCancelled;
use Modules\Billing\Events\SubscriptionCreated;
use Modules\Billing\Events\SubscriptionUpdated;
use Modules\Billing\Events\TrialEnding;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\WebhookEvent;
use Modules\Billing\Services\Gateways\StripeGateway;
use Modules\Billing\Settings\BillingSettings;

class BillingService
{
    public function __construct(
        private PaymentGatewayManager $manager,
        private PurchaseEligibility $eligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $billingDetails
     */
    public function processCheckout(CheckoutSession $session, User $user, string $successUrl, string $cancelUrl, array $billingDetails = [], ?string $coupon = null): CheckoutResultData
    {
        $customer = $this->ensureCustomer($user, billingDetails: $billingDetails);

        // The first hand-off is the only one: a second provider session would
        // replace the stored ID, and paying the first would then go unrecognised.
        // Checked before the price is read, so retiring a plan cannot 404 a
        // checkout that is already paid for at the provider.
        if ($session->provider_url && $session->customer_id === $customer->id) {
            return new CheckoutResultData(
                sessionId: $session->provider_session_id,
                url: $session->provider_url,
                provider: $session->provider,
            );
        }

        $price = $session->price()->purchasable()->with('product')->firstOrFail();

        $this->assertCanBuy($user, $price);

        // One statement, so two requests cannot both bind a fresh session.
        $claimed = CheckoutSession::whereKey($session->id)
            ->where(fn (Builder $query) => $query->whereNull('customer_id')->orWhere('customer_id', $customer->id))
            ->update(['customer_id' => $customer->id]);

        // MySQL reports rows *changed*, not rows matched, so a second request
        // from the same customer writes the ID it already holds and comes back
        // as zero. Only a session owned by somebody else is a refusal.
        if ($claimed === 0 && ! CheckoutSession::whereKey($session->id)->where('customer_id', $customer->id)->exists()) {
            throw new AuthorizationException;
        }

        $session = $this->settleRequest($session, $customer, $price, $successUrl, $cancelUrl, $coupon);

        $data = new CheckoutData(
            customer: $customer,
            price: $price,
            successUrl: $session->success_url,
            cancelUrl: $session->cancel_url,
            coupon: $session->coupon,
            trialDays: $session->trial_days ?: null,
            trialRequiresPaymentMethod: $session->trial_requires_payment_method ?? true,
            // Two requests racing past the check above get the same provider session.
            idempotencyKey: 'checkout_'.$session->uuid,
        );

        $result = $this->manager->driver()->createCheckoutSession($data);

        $session->update([
            'customer_id' => $customer->id,
            'provider' => $result->provider,
            'provider_session_id' => $result->sessionId,
            'provider_url' => $result->url,
        ]);

        return $result;
    }

    /**
     * Decide this checkout's request, once, and write it down before the hand-off.
     *
     * The idempotency key stands for one request, so a retry sends the first
     * one again and the expiry command can replay it to find a session whose
     * answer was lost. The trial is decided under the customer's row lock, with
     * the session re-read inside it, so neither a second checkout nor a second
     * request holding this one can claim or undo the one trial.
     */
    private function settleRequest(CheckoutSession $session, Customer $customer, Price $price, string $successUrl, string $cancelUrl, ?string $coupon): CheckoutSession
    {
        return DB::transaction(function () use ($session, $customer, $price, $successUrl, $cancelUrl, $coupon): CheckoutSession {
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            $session->refresh();

            if ($session->trial_days === null) {
                $days = $price->product?->trial_days;

                $session->forceFill([
                    'trial_days' => $days && ! $customer->hasTrialed() ? $days : 0,
                    'trial_requires_payment_method' => app(BillingSettings::class)->trial_requires_payment_method,
                ]);
            }

            if ($session->success_url === null) {
                $session->forceFill(['success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'coupon' => $coupon]);
            }

            $session->save();

            return $session;
        });
    }

    public function handleWebhook(string $provider, Request $request): void
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

    /**
     * Checked on the server because the pricing page only hides the button: a
     * second subscription would bill the owner twice. `PurchaseEligibility` is
     * the rule; the pricing page asks it too.
     *
     * @throws ValidationException
     */
    public function assertCanBuy(?BillingOwner $owner, Price $price): void
    {
        $refusal = $this->eligibility->check($owner, $price);

        if ($refusal) {
            throw ValidationException::withMessages(['price_id' => $refusal->message()]);
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

    /**
     * Stop renewal and keep access until the end of the period already paid for.
     * The local row is written straight away rather than left to the webhook,
     * so the page the customer returns to already shows the end date.
     */
    public function cancelAtPeriodEnd(Subscription $subscription): void
    {
        $periodEnd = $this->manager->driver($subscription->provider)->cancelSubscription($subscription);
        $endsAt = $periodEnd ?? $subscription->current_period_ends_at;

        $subscription->update([
            'cancelled_at' => now(),
            'ends_at' => $endsAt,
            'current_period_ends_at' => $endsAt,
        ]);
    }

    public function resume(Subscription $subscription): void
    {
        $this->manager->driver()->resumeSubscription($subscription);
    }

    /**
     * @return bool Whether the checkout is paid — including when the webhook got
     *              here first, which is what tells the caller to congratulate.
     */
    public function fulfillCheckoutIfNeeded(string $providerSessionId): bool
    {
        $provider = $this->manager->getDefaultDriver();
        $session = CheckoutSession::where('provider', $provider)->where('provider_session_id', $providerSessionId)->first();

        if (! $session) {
            return false;
        }

        if ($session->status === CheckoutSessionStatus::Completed) {
            return true;
        }

        try {
            $gateway = $this->manager->driver();

            if (! $gateway instanceof StripeGateway) {
                Log::info('fulfillCheckoutIfNeeded skipped: gateway is not Stripe', [
                    'session_id' => $providerSessionId,
                ]);

                return false;
            }

            $stripeSession = $gateway->retrieveCheckoutSession($providerSessionId);
        } catch (\Throwable $e) {
            Log::warning('Failed to retrieve checkout session for redirect fulfillment', [
                'session_id' => $providerSessionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($this->awaitsPayment($stripeSession)) {
            return false;
        }

        $this->onCheckoutCompleted(new WebhookData(
            type: WebhookEventType::CheckoutCompleted,
            provider: $provider,
            providerEventId: 'redirect_fulfill_'.$providerSessionId,
            payload: $stripeSession,
        ));

        return true;
    }

    public function getPlanChangeUrl(Subscription $subscription): string
    {
        return $this->manager->driver($subscription->provider)->getPlanChangeUrl($subscription);
    }

    public function getManagementUrl(User $user): string
    {
        $customer = Customer::where('user_id', $user->id)->firstOrFail();

        return $this->manager->driver()->getManagementUrl($customer);
    }

    /**
     * @param  array<string, mixed>  $billingDetails
     */
    private function ensureCustomer(User $user, ?string $provider = null, array $billingDetails = []): Customer
    {
        $name = $billingDetails['name'] ?? $user->name;
        $email = $billingDetails['email'] ?? $user->email;
        $phone = $billingDetails['phone'] ?? null;
        /** @var array<string, string>|null $rawAddress */
        $rawAddress = $billingDetails['address'] ?? null;

        $addressData = $rawAddress && ! empty($rawAddress['country'])
            ? new AddressData(
                country: $rawAddress['country'],
                line1: $rawAddress['line1'] ?? $rawAddress['street'] ?? null,
                line2: $rawAddress['line2'] ?? null,
                city: $rawAddress['city'] ?? null,
                state: $rawAddress['state'] ?? null,
                postalCode: $rawAddress['postal_code'] ?? null,
            )
            : null;

        $customerData = new CustomerData(
            user: $user,
            name: $name,
            email: $email,
            phone: $phone,
            address: $addressData,
        );

        $customer = Customer::where('user_id', $user->id)->first();

        if ($customer) {
            $updates = array_filter([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
            ], fn ($v) => $v !== null);

            if ($addressData) {
                $updates['address'] = $addressData->toArray();
            }

            if (blank($customer->provider_customer_id)) {
                $updates['provider_customer_id'] = $this->manager->driver($customer->provider)
                    ->createCustomer($customerData);
            }

            $customer->update($updates);

            return $customer;
        }

        $provider ??= $this->manager->getDefaultDriver();

        return Customer::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_customer_id' => $this->manager->driver($provider)->createCustomer($customerData),
            'email' => $email,
            'name' => $name,
            'phone' => $phone,
            'address' => $addressData?->toArray(),
        ]);
    }

    private function ensurePaymentMethod(Customer $customer, string $providerId, string $provider, bool $makeDefault = true): ?PaymentMethod
    {
        $gateway = $this->manager->driver($provider);

        if (! $gateway instanceof StripeGateway) {
            return null;
        }

        $data = $gateway->resolvePaymentMethod($providerId);

        if (! $data) {
            return null;
        }

        return DB::transaction(function () use ($customer, $data, $provider, $makeDefault) {
            $existing = PaymentMethod::where('provider', $provider)->where('provider_payment_method_id', $data->providerPaymentMethodId)->first();

            if ($existing) {
                if ($makeDefault && ! $existing->is_default) {
                    PaymentMethod::where('customer_id', $customer->id)
                        ->where('is_default', true)
                        ->lockForUpdate()
                        ->update(['is_default' => false]);
                    $existing->update(['is_default' => true]);
                }

                return $existing;
            }

            if ($makeDefault) {
                PaymentMethod::where('customer_id', $customer->id)
                    ->where('is_default', true)
                    ->lockForUpdate()
                    ->update(['is_default' => false]);
            }

            return PaymentMethod::create([
                'customer_id' => $customer->id,
                'provider' => $provider,
                'provider_payment_method_id' => $data->providerPaymentMethodId,
                'type' => $data->type,
                'details' => $data->details->toArray(),
                'is_default' => $makeDefault,
            ]);
        });
    }

    private function customerFor(WebhookData $webhook): ?Customer
    {
        $providerCustomerId = $webhook->payload['customer'] ?? null;

        // A null ID would match every customer the provider has never seen.
        if (! is_string($providerCustomerId) || $providerCustomerId === '') {
            return null;
        }

        return Customer::where('provider', $webhook->provider)
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
    private function subscriptionForEvent(WebhookData $webhook, string $providerSubscriptionId): ?Subscription
    {
        $subscription = $this->subscriptionFor($webhook, $providerSubscriptionId);

        if ($subscription) {
            return $subscription;
        }

        $context = [
            'provider' => $webhook->provider,
            'provider_subscription_id' => $providerSubscriptionId,
            'provider_customer_id' => $webhook->payload['customer'] ?? null,
        ];

        if (! $this->customerFor($webhook)) {
            Log::warning('Ignoring subscription event for an unknown customer', $context);

            return null;
        }

        Log::warning('Subscription not found for a known customer', $context);

        throw new \RuntimeException("Subscription not found: {$providerSubscriptionId}");
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

            $this->transition($current, $status, $updates);

            return $current;
        });
    }

    /**
     * Write a row locked by the caller, moving it to `$status` under the
     * delinquency rules when one is given. A status change bumps the revision,
     * so a provider read taken before it can tell it is about an older row.
     *
     * @param  array<string, mixed>  $alsoUpdate
     */
    private function transition(Subscription $current, ?SubscriptionStatus $status, array $alsoUpdate = []): void
    {
        $delinquency = $status ? $this->delinquencyUpdates($current, $status) : [];

        $current->update($delinquency === []
            ? $alsoUpdate
            : [...$alsoUpdate, ...$delinquency, 'state_revision' => $current->state_revision + 1]);
    }

    /**
     * Suspend a subscription whose grace window has closed.
     *
     * The status is re-checked under the row's lock, so a recovery that landed
     * between the sweeper's query and this call wins rather than being undone.
     */
    public function suspendIfGraceHasRunOut(Subscription $subscription): bool
    {
        $suspended = DB::transaction(function () use ($subscription): ?Subscription {
            $current = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($current->status !== SubscriptionStatus::PastDue || ! $current->grace_ends_at?->isPast()) {
                return null;
            }

            $this->transition($current, SubscriptionStatus::Suspended);

            return $current;
        });

        if ($suspended) {
            $this->announceDelinquency($suspended);
        }

        return $suspended !== null;
    }

    /** A card added in the provider's portal, which is where a trialing customer adds one. */
    private function onPaymentMethodAttached(WebhookData $webhook): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;

        $customer = $this->customerFor($webhook);

        if ($customer && is_string($payload['id'] ?? null)) {
            // Attaching a card does not make it the one invoices are charged to.
            $this->ensurePaymentMethod($customer, $payload['id'], $webhook->provider, makeDefault: false);
        }
    }

    /** The card invoices are charged to, which the customer may change or clear in the portal. */
    private function onCustomerUpdated(WebhookData $webhook): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;

        $customer = is_string($payload['id'] ?? null)
            ? Customer::where('provider', $webhook->provider)->where('provider_customer_id', $payload['id'])->first()
            : null;

        if (! $customer) {
            return;
        }

        $default = $payload['invoice_settings']['default_payment_method'] ?? null;

        if (is_string($default)) {
            $this->ensurePaymentMethod($customer, $default, $webhook->provider);

            return;
        }

        PaymentMethod::where('customer_id', $customer->id)->where('is_default', true)->update(['is_default' => false]);
    }

    /** Removed there too, and a subscription pointing at it is left without one. */
    private function onPaymentMethodDetached(WebhookData $webhook): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;

        // Without an ID this would match every row missing one.
        if (! is_string($payload['id'] ?? null)) {
            return;
        }

        PaymentMethod::where('provider', $webhook->provider)
            ->where('provider_payment_method_id', $payload['id'])
            ->each(fn (PaymentMethod $method) => $method->delete());
    }

    /**
     * Tell the customer what just happened to their subscription.
     *
     * Both mails hang off a transition rather than an event type, so a provider
     * repeating itself says nothing twice. They are best-effort: a crash between
     * the committed change and the queued job loses one.
     */
    private function announceDelinquency(Subscription $subscription): void
    {
        if ($subscription->wasChanged('status') && $subscription->status === SubscriptionStatus::Suspended) {
            event(new AccessSuspended($subscription));

            return;
        }

        if ($subscription->wasChanged('grace_ends_at') && $subscription->status === SubscriptionStatus::PastDue) {
            event(new GraceStarted($subscription));
        }
    }

    /** The provider warns before a trial converts; the customer hears it from us. */
    private function onTrialWillEnd(WebhookData $webhook): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;

        $subscription = $this->subscriptionForEvent($webhook, $payload['id']);

        if (! $subscription) {
            return;
        }

        $subscription = $this->applyIfCurrent($subscription, $webhook, $this->trialDates($payload));

        if ($subscription) {
            event(new TrialEnding($subscription));
        }
    }

    /**
     * The provider's trial dates, in the app's columns. Stripe keeps them on the
     * subscription for good, which is what makes "has this customer ever
     * trialed" answerable from history.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, Carbon>
     */
    private function trialDates(array $payload): array
    {
        $dates = [];

        foreach (['trial_start' => 'trial_starts_at', 'trial_end' => 'trial_ends_at'] as $key => $column) {
            if (! empty($payload[$key])) {
                $dates[$column] = Carbon::createFromTimestamp($payload[$key]);
            }
        }

        return $dates;
    }

    /**
     * What the provider says this subscription is, in the app's words. A trial
     * is active there and here; anything unknown leaves the row as it is.
     *
     * @param  array<string, mixed>  $payload
     */
    private function providerStatus(array $payload, Subscription $subscription): SubscriptionStatus
    {
        return match ($payload['status'] ?? null) {
            'active', 'trialing' => SubscriptionStatus::Active,
            'past_due' => SubscriptionStatus::PastDue,
            'unpaid' => SubscriptionStatus::Suspended,
            'canceled', 'incomplete_expired' => SubscriptionStatus::Cancelled,
            'incomplete', 'paused' => SubscriptionStatus::Pending,
            default => $subscription->status,
        };
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
            $payload = $this->manager->driver($subscription->provider)->retrieveSubscription($subscription->provider_subscription_id);
            $status = $this->providerStatus($payload, $subscription);

            $applied = DB::transaction(function () use ($subscription, $revision, $status): ?Subscription {
                $current = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

                if ($current->state_revision !== $revision) {
                    return null;
                }

                $this->transition($current, $status);

                return $current;
            });

            if ($applied) {
                $this->announceDelinquency($applied);

                return;
            }
        }

        throw new \RuntimeException("Could not reconcile subscription {$subscription->id}: it kept changing while the provider was asked.");
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
    private function delinquencyUpdates(Subscription $current, SubscriptionStatus $status): array
    {
        if ($current->status === SubscriptionStatus::Suspended && $status === SubscriptionStatus::PastDue) {
            return [];
        }

        if ($status === SubscriptionStatus::PastDue) {
            $deadline = $current->grace_ends_at ?? now()->addDays(app(BillingSettings::class)->grace_period_days);

            // A window of zero days, or one that closed while nobody was looking,
            // is a suspension rather than a grace period.
            return [
                'status' => $deadline->isFuture() ? SubscriptionStatus::PastDue : SubscriptionStatus::Suspended,
                'grace_ends_at' => $deadline,
            ];
        }

        if ($status === SubscriptionStatus::Suspended) {
            // A deadline already passed stays; one still ahead is pulled back to now.
            return [
                'status' => $status,
                'grace_ends_at' => $current->grace_ends_at?->isPast() ? $current->grace_ends_at : now(),
            ];
        }

        // Active (a trial included), pending or cancelled: the episode is over.
        return ['status' => $status, 'grace_ends_at' => null];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function parseCurrency(array $payload): Currency
    {
        return Currency::tryFrom(strtoupper($payload['currency'] ?? '')) ?? Currency::default();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSubscriptionId(array $payload): ?string
    {
        return $payload['subscription'] ?? $payload['parent']['subscription_details']['subscription'] ?? null;
    }

    /**
     * `unpaid` is a delayed payment method that has not settled; the provider
     * sends a second event once it does. Anything else — including
     * `no_payment_required`, which is what a trial or a fully discounted price
     * reports — owes nothing now and is fulfilled.
     *
     * @param  array<string, mixed>  $session
     */
    private function awaitsPayment(array $session): bool
    {
        return ($session['payment_status'] ?? null) === 'unpaid';
    }

    private function createPaymentFromWebhook(WebhookData $webhook, PaymentStatus $status, string $amountKey): ?Payment
    {
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;

        $customer = $this->customerFor($webhook);

        if (! $customer) {
            return null;
        }

        $subscriptionId = $this->resolveSubscriptionId($payload);
        $subscription = $subscriptionId ? $this->subscriptionFor($webhook, $subscriptionId) : null;

        // Its checkout event is still on the way. Failing here has the provider
        // deliver this one again once the subscription exists locally.
        if ($subscriptionId && ! $subscription) {
            throw new \RuntimeException("Subscription not found: {$subscriptionId}");
        }

        $pm = ($payload['default_payment_method'] ?? null)
            ? $this->ensurePaymentMethod($customer, $payload['default_payment_method'], $webhook->provider)
            : null;

        $providerPaymentId = $payload['payment_intent'] ?? $payload['id'];

        $attributes = [
            'customer_id' => $customer->id,
            'provider' => $webhook->provider,
            'subscription_id' => $subscription?->id,
            'price_id' => $subscription?->price_id,
            'provider_payment_id' => $providerPaymentId,
            'currency' => $this->parseCurrency($payload),
            'amount' => $payload[$amountKey] ?? 0,
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
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;

        if ($this->awaitsPayment($payload)) {
            Log::info('Checkout completed but not yet paid', ['session_id' => $payload['id']]);

            return;
        }

        $subscriptionId = $payload['subscription'] ?? null;
        /** @var array{subscription: ?Subscription, payment: ?Payment} $result */
        $result = ['subscription' => null, 'payment' => null];
        $wasJustCreated = false;

        // Locked and re-read inside the transaction: the return redirect and the
        // webhook both complete the same session, and only one may record it.
        $session = DB::transaction(function () use ($payload, $webhook, $subscriptionId, &$result, &$wasJustCreated) {
            $session = CheckoutSession::where('provider', $webhook->provider)
                ->where('provider_session_id', $payload['id'])
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

            if ($subscriptionId) {
                $pm = $this->ensurePaymentMethod($customer, $subscriptionId, $webhook->provider);

                $subscription = Subscription::firstOrCreate(
                    ['provider' => $webhook->provider, 'provider_subscription_id' => $subscriptionId],
                    [
                        'customer_id' => $session->customer_id,
                        'price_id' => $session->price_id,
                        'payment_method_id' => $pm?->id,
                        'status' => SubscriptionStatus::Active,
                        'current_period_starts_at' => now(),
                    ],
                );

                $wasJustCreated = $subscription->wasRecentlyCreated;
                $result['subscription'] = $subscription;

                $result['payment'] = Payment::create([
                    'customer_id' => $session->customer_id,
                    'provider' => $webhook->provider,
                    'subscription_id' => $subscription->id,
                    'price_id' => $session->price_id,
                    'payment_method_id' => $pm?->id,
                    'currency' => $this->parseCurrency($payload),
                    'amount' => $payload['amount_total'] ?? 0,
                    'status' => PaymentStatus::Succeeded,
                ]);
            } elseif ($payload['payment_intent'] ?? null) {
                $pm = $this->ensurePaymentMethod($customer, $payload['payment_intent'], $webhook->provider);

                $result['payment'] = Payment::create([
                    'customer_id' => $session->customer_id,
                    'provider' => $webhook->provider,
                    'price_id' => $session->price_id,
                    'payment_method_id' => $pm?->id,
                    'provider_payment_id' => $payload['payment_intent'],
                    'currency' => $this->parseCurrency($payload),
                    'amount' => $payload['amount_total'] ?? 0,
                    'status' => PaymentStatus::Succeeded,
                ]);
            }

            return $session;
        });

        $this->endSubscriptionReplacedByLifetime($webhook->provider, $payload['id']);

        if (! $session) {
            return;
        }

        // Sync period dates from gateway after transaction commits (avoids API call inside transaction)
        if ($result['subscription'] && $wasJustCreated && $subscriptionId) {
            $this->syncSubscriptionPeriod($result['subscription'], $subscriptionId, $webhook->provider);
        }

        // Fire events after transaction commits
        if ($result['payment']) {
            event(new PaymentSucceeded($result['payment']));
        }

        if ($result['subscription']) {
            event(new SubscriptionCreated($result['subscription']));
        }

        event(new CheckoutCompleted($session));
    }

    private function onSubscriptionUpdated(WebhookData $webhook): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;

        $subscription = $this->subscriptionForEvent($webhook, $payload['id']);

        if (! $subscription) {
            return;
        }

        // The provider call happens before the lock below, so the row is never
        // held across a network round trip.
        $pm = ($payload['default_payment_method'] ?? null)
            ? $this->ensurePaymentMethod($subscription->customer, $payload['default_payment_method'], $webhook->provider)
            : null;

        $status = $this->providerStatus($payload, $subscription);

        $updates = ['last_event_at' => $webhook->occurredAt, ...$this->trialDates($payload)];

        if ($pm) {
            $updates['payment_method_id'] = $pm->id;
        } elseif (array_key_exists('default_payment_method', $payload) && $payload['default_payment_method'] === null) {
            // Cleared at the provider: invoices fall back to the customer's default.
            $updates['payment_method_id'] = null;
        }

        // A plan changed at the provider (its billing portal) arrives here.
        $priceId = $this->localPriceId($webhook->provider, $payload['items']['data'][0]['price']['id'] ?? null);

        if ($priceId) {
            $updates['price_id'] = $priceId;
        }

        $period = $this->subscriptionPeriod($payload);

        if ($period['start']) {
            $updates['current_period_starts_at'] = Carbon::createFromTimestamp($period['start']);
        }

        if ($period['end']) {
            $updates['current_period_ends_at'] = Carbon::createFromTimestamp($period['end']);
        }

        if (isset($payload['cancel_at_period_end']) && $payload['cancel_at_period_end']) {
            $updates['cancelled_at'] = now();
            $endsAt = $period['end'] ?? $payload['cancel_at'] ?? null;
            $updates['ends_at'] = $endsAt ? Carbon::createFromTimestamp($endsAt) : null;
        } elseif (isset($payload['cancel_at']) && $payload['cancel_at']) {
            $updates['cancelled_at'] = now();
            $updates['ends_at'] = Carbon::createFromTimestamp($payload['cancel_at']);
            // Reaching here means the first branch already ruled out a truthy
            // `cancel_at_period_end`, so only its presence still needs checking.
        } elseif (isset($payload['cancel_at_period_end']) && empty($payload['cancel_at'])) {
            $updates['cancelled_at'] = null;
            $updates['ends_at'] = null;
        }

        $subscription = $this->applyIfCurrent($subscription, $webhook, $updates, $status);

        if (! $subscription) {
            return;
        }

        Log::info('Subscription updated', ['subscription_id' => $subscription->id, 'status' => $subscription->status->value]);

        $this->announceDelinquency($subscription);

        event(new SubscriptionUpdated($subscription));
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
            $this->cancelAtPeriodEnd($subscription);
        }
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
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;
        $paymentIntent = $payload['payment_intent'] ?? null;

        // A charge made without a payment intent names nothing we record, and
        // looking up a null ID would match an unrelated row.
        if (! is_string($paymentIntent) || $paymentIntent === '') {
            Log::info('Refund without a payment intent, acknowledged', ['charge' => $payload['id'] ?? null]);

            return;
        }

        $payment = Payment::where('provider', $webhook->provider)
            ->where('provider_payment_id', $paymentIntent)
            ->first();

        if (! $payment) {
            if (! $this->customerFor($webhook)) {
                Log::warning('Ignoring refund for an unknown customer', ['payment_intent' => $paymentIntent]);

                return;
            }

            throw new \RuntimeException("Payment not found for refund: {$paymentIntent}");
        }

        $payment->update(array_filter([
            'amount_refunded' => $payload['amount_refunded'] ?? null,
            'status' => ($payload['refunded'] ?? false) === true ? PaymentStatus::Refunded : null,
        ], fn ($value) => $value !== null));
    }

    private function onSubscriptionDeleted(WebhookData $webhook): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;

        $subscription = $this->subscriptionForEvent($webhook, $payload['id']);

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
        $payment = $this->createPaymentFromWebhook($webhook, PaymentStatus::Succeeded, 'amount_paid');

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
        $payment = $this->createPaymentFromWebhook($webhook, PaymentStatus::Failed, 'amount_due');

        if (! $payment) {
            return;
        }

        // The subscription's own event says it fell behind, in a stream the
        // provider does order. Inferring it from an invoice would race with it.
        event(new PaymentFailed($payment));
    }

    private function onInvoicePaid(WebhookData $webhook): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $webhook->payload;

        $customer = $this->customerFor($webhook);

        if (! $customer) {
            return;
        }

        $subscriptionId = $this->resolveSubscriptionId($payload);
        $subscription = $subscriptionId ? $this->subscriptionFor($webhook, $subscriptionId) : null;

        $invoice = Invoice::updateOrCreate(
            ['provider' => $webhook->provider, 'provider_invoice_id' => $payload['id']],
            [
                'customer_id' => $customer->id,
                'subscription_id' => $subscription?->id,
                'number' => $payload['number'] ?? null,
                'currency' => $this->parseCurrency($payload),
                'subtotal' => $payload['subtotal'] ?? 0,
                'tax' => $payload['tax'] ?? 0,
                'total' => $payload['total'] ?? 0,
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
                'hosted_invoice_url' => $payload['hosted_invoice_url'] ?? null,
                'pdf_url' => $payload['invoice_pdf'] ?? null,
            ],
        );

        // Proration lines cover only the remainder of the period, so the period
        // is the span of every line rather than whatever the first one says.
        $periods = array_column($payload['lines']['data'] ?? [], 'period');

        if ($subscription && $periods !== []) {
            $subscription->update([
                'current_period_starts_at' => Carbon::createFromTimestamp(min(array_column($periods, 'start'))),
                'current_period_ends_at' => Carbon::createFromTimestamp(max(array_column($periods, 'end'))),
            ]);
        }

        event(new InvoicePaid($invoice));
    }

    private function syncSubscriptionPeriod(Subscription $subscription, string $providerSubscriptionId, string $provider): void
    {
        try {
            $gateway = $this->manager->driver($provider);

            if (! $gateway instanceof StripeGateway) {
                return;
            }

            $remote = $gateway->retrieveSubscription($providerSubscriptionId);
            $period = $this->subscriptionPeriod($remote);

            // Read at checkout rather than waiting for the first update event,
            // so a trial is on the row within a second of the buyer paying.
            $updates = $this->trialDates($remote);
            if ($period['start']) {
                $updates['current_period_starts_at'] = Carbon::createFromTimestamp($period['start']);
            }
            if ($period['end']) {
                $updates['current_period_ends_at'] = Carbon::createFromTimestamp($period['end']);
            }

            if ($updates) {
                $subscription->update($updates);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to sync subscription period from gateway', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Stripe keeps the billing period on each subscription item since API
     * version 2025-03-31; webhook endpoints pinned earlier still send it at the
     * top level. Subscriptions here carry one item, so the first is the period.
     *
     * @param  array<string, mixed>  $subscription
     * @return array{start: ?int, end: ?int}
     */
    private function subscriptionPeriod(array $subscription): array
    {
        $item = $subscription['items']['data'][0] ?? [];

        return [
            'start' => $item['current_period_start'] ?? $subscription['current_period_start'] ?? null,
            'end' => $item['current_period_end'] ?? $subscription['current_period_end'] ?? null,
        ];
    }
}
