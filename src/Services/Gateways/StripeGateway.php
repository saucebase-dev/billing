<?php

namespace Modules\Billing\Services\Gateways;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Data\CatalogPriceData;
use Modules\Billing\Data\CatalogProductData;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Data\CustomerData;
use Modules\Billing\Data\PaymentMethodData;
use Modules\Billing\Data\PaymentMethodDetails;
use Modules\Billing\Data\Webhook\CheckoutSessionData;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\CheckoutExpiry;
use Modules\Billing\Enums\PaymentMethodType;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Exceptions\GatewayOperationFailed;
use Modules\Billing\Exceptions\InvalidWebhookSignature;
use Modules\Billing\Exceptions\ProviderError;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Price as StripePrice;
use Stripe\Product as StripeProduct;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeGateway implements PaymentGatewayInterface
{
    private const array EVENT_MAP = [
        'checkout.session.completed' => WebhookEventType::CheckoutCompleted,
        'checkout.session.async_payment_succeeded' => WebhookEventType::CheckoutCompleted,
        'customer.subscription.updated' => WebhookEventType::SubscriptionUpdated,
        'customer.subscription.deleted' => WebhookEventType::SubscriptionDeleted,
        'customer.subscription.trial_will_end' => WebhookEventType::SubscriptionTrialWillEnd,
        'invoice.payment_succeeded' => WebhookEventType::PaymentSucceeded,
        'invoice.payment_failed' => WebhookEventType::PaymentFailed,
        'invoice.paid' => WebhookEventType::InvoicePaid,
        'charge.refunded' => WebhookEventType::PaymentRefunded,
        'payment_method.attached' => WebhookEventType::PaymentMethodAttached,
        'payment_method.detached' => WebhookEventType::PaymentMethodDetached,
        'customer.updated' => WebhookEventType::CustomerUpdated,
    ];

    public function __construct(
        private StripeClient $stripe,
    ) {}

    public function createCustomer(CustomerData $data): string
    {
        $params = [
            'name' => $data->name,
            'email' => $data->email,
        ];

        if ($data->phone) {
            $params['phone'] = $data->phone;
        }

        if ($data->address) {
            $params['address'] = array_filter([
                'line1' => $data->address->line1,
                'line2' => $data->address->line2,
                'city' => $data->address->city,
                'state' => $data->address->state,
                'postal_code' => $data->address->postalCode,
                'country' => $data->address->country,
            ], fn ($v) => $v !== null);
        }

        return $this->call('create a customer', fn () => $this->stripe->customers->create($params)->id);
    }

    public function createCheckoutSession(CheckoutData $data): CheckoutResultData
    {
        $isRecurring = $data->price->interval !== null;

        $params = [
            'customer' => $data->customer->provider_customer_id,
            'mode' => $isRecurring ? 'subscription' : 'payment',
            'line_items' => [
                [
                    'price' => $data->price->provider_price_id,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $data->successUrl,
            'cancel_url' => $data->cancelUrl,
        ];

        if ($isRecurring && $data->trialDays) {
            $params['subscription_data']['trial_period_days'] = $data->trialDays;

            // With no payment details at the end — never given, or removed in the
            // portal — Stripe cancels rather than leaving a subscription paused.
            $params['subscription_data']['trial_settings']['end_behavior']['missing_payment_method'] = 'cancel';

            if (! $data->trialRequiresPaymentMethod) {
                $params['payment_method_collection'] = 'if_required';
            }
        }

        // Stripe rejects a session that both carries a discount and invites one,
        // so a resolved code wins and everyone else gets the field on Stripe's page.
        $promotionCode = $data->coupon ? $this->resolvePromotionCode($data->coupon) : null;

        if ($promotionCode !== null) {
            $params['discounts'] = [['promotion_code' => $promotionCode]];
        } else {
            $params['allow_promotion_codes'] = true;
        }

        $options = $data->idempotencyKey ? ['idempotency_key' => $data->idempotencyKey] : [];

        $session = $this->call('create a checkout session', fn () => $this->stripe->checkout->sessions->create($params, $options));

        return new CheckoutResultData(
            sessionId: $session->id,
            url: $session->url,
            provider: 'stripe',
        );
    }

    public function expireCheckoutSession(string $providerSessionId): CheckoutExpiry
    {
        try {
            $status = $this->call('expire a checkout session', fn () => $this->stripe->checkout->sessions->expire($providerSessionId)->status, $providerSessionId);
        } catch (GatewayOperationFailed) {
            // Stripe refuses a session that is not open, which includes one an
            // earlier call expired whose answer was lost: ask what it is. If that
            // fails too, the outcome is unknown and the exception says so.
            $status = $this->call('read a checkout session', fn () => $this->stripe->checkout->sessions->retrieve($providerSessionId)->status, $providerSessionId);
        }

        return match ($status) {
            'expired' => CheckoutExpiry::Expired,
            'complete' => CheckoutExpiry::Completed,
            // Still open after an expiry that failed: nothing is proven.
            default => throw new GatewayOperationFailed('stripe', 'expire a checkout session', providerResourceId: $providerSessionId),
        };
    }

    /**
     * Turn a code somebody typed into the promotion code ID Stripe wants.
     *
     * Returns null when the code is unknown or no longer active, which is why the
     * caller falls back to letting Stripe ask: an expired code should not cost
     * somebody their checkout.
     */
    private function resolvePromotionCode(string $code): ?string
    {
        try {
            $matches = $this->call('look up a promotion code', fn () => $this->stripe->promotionCodes->all([
                'code' => $code,
                'active' => true,
                'limit' => 1,
            ]));
        } catch (GatewayOperationFailed $e) {
            // Reported, then the documented fallback: Stripe's page asks for the code.
            report($e);

            return null;
        }

        return $matches->data[0]->id ?? null;
    }

    public function cancelSubscription(Subscription $subscription): ?\DateTimeInterface
    {
        $stripeSub = $this->call('cancel a subscription', fn () => $this->stripe->subscriptions->update($subscription->provider_subscription_id, [
            'cancel_at_period_end' => true,
        ]), $subscription->provider_subscription_id);

        // Stripe sets `cancel_at` to the period end for this call; older API
        // versions only reported the period on the subscription itself.
        $endsAt = $stripeSub->cancel_at ?? $stripeSub->items->data[0]->current_period_end ?? null;

        return $endsAt ? Carbon::createFromTimestamp($endsAt) : null;
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        $this->call('resume a subscription', fn () => $this->stripe->subscriptions->update($subscription->provider_subscription_id, [
            'cancel_at_period_end' => false,
        ]), $subscription->provider_subscription_id);
    }

    public function getManagementUrl(Customer $customer): string
    {
        $session = $this->call('open the billing portal', fn () => $this->stripe->billingPortal->sessions->create([
            'customer' => $customer->provider_customer_id,
            'return_url' => route('settings.billing'),
        ]), $customer->provider_customer_id);

        return $session->url;
    }

    /**
     * Opens the billing portal straight on its plan picker. Which plans it
     * offers, and whether downgrades wait for the period end, are set in the
     * portal's own configuration in the Stripe dashboard.
     */
    public function getPlanChangeUrl(Subscription $subscription): string
    {
        $session = $this->call('open the plan change portal', fn () => $this->stripe->billingPortal->sessions->create([
            'customer' => $subscription->customer->provider_customer_id,
            'return_url' => route('settings.billing'),
            'flow_data' => [
                'type' => 'subscription_update',
                'subscription_update' => ['subscription' => $subscription->provider_subscription_id],
                'after_completion' => [
                    'type' => 'redirect',
                    'redirect' => ['return_url' => route('settings.billing')],
                ],
            ],
        ]), $subscription->provider_subscription_id);

        return $session->url;
    }

    public function resolvePaymentMethod(string $reference): ?PaymentMethodData
    {
        return $this->call('resolve a payment method', fn () => $this->paymentMethod($reference), $reference);
    }

    private function paymentMethod(string $reference): ?PaymentMethodData
    {
        $pmId = match (true) {
            str_starts_with($reference, 'pm_') => $reference,
            str_starts_with($reference, 'sub_') => $this->stripe->subscriptions->retrieve($reference)->default_payment_method,
            str_starts_with($reference, 'pi_') => $this->stripe->paymentIntents->retrieve($reference)->payment_method,
            default => null,
        };

        if (! $pmId) {
            return null;
        }

        $pm = $this->stripe->paymentMethods->retrieve($pmId);
        $type = PaymentMethodType::tryFrom($pm->type) ?? PaymentMethodType::Unknown;

        $details = match ($type) {
            PaymentMethodType::Card => new PaymentMethodDetails(
                brand: $pm->card->display_brand ?? $pm->card?->brand,
                last4: $pm->card?->last4,
                expMonth: $pm->card?->exp_month,
                expYear: $pm->card?->exp_year,
                funding: $pm->card?->funding,
                wallet: $pm->card?->wallet?->type,
            ),
            PaymentMethodType::SepaDebit => new PaymentMethodDetails(
                last4: $pm->sepa_debit?->last4,
                country: $pm->sepa_debit?->country,
            ),
            PaymentMethodType::UsBankAccount => new PaymentMethodDetails(
                bankName: $pm->us_bank_account?->bank_name,
                last4: $pm->us_bank_account?->last4,
            ),
            PaymentMethodType::PayPal => new PaymentMethodDetails(
                email: $pm->paypal?->payer_email,
            ),
            PaymentMethodType::Link => new PaymentMethodDetails(
                email: $pm->link?->email,
            ),
            default => new PaymentMethodDetails,
        };

        return new PaymentMethodData(
            providerPaymentMethodId: $pm->id,
            type: $type,
            details: $details,
        );
    }

    public function retrieveCheckoutSession(string $providerSessionId): CheckoutSessionData
    {
        return StripeEventMapper::checkoutSession($this->call('read a checkout session', fn () => $this->stripe->checkout->sessions->retrieve($providerSessionId)->toArray(), $providerSessionId));
    }

    public function retrieveSubscription(string $providerSubscriptionId): SubscriptionStateData
    {
        return StripeEventMapper::subscription($this->call('read a subscription', fn () => $this->stripe->subscriptions->retrieve($providerSubscriptionId)->toArray(), $providerSubscriptionId));
    }

    public function listCatalog(): array
    {
        return $this->call('list the catalog', fn () => $this->catalog());
    }

    /** @return list<CatalogProductData> */
    private function catalog(): array
    {
        // Archived prices come too: a price a subscription still bills on must
        // stay known locally, just no longer purchasable.
        $prices = collect($this->stripe->prices->all(['limit' => 100, 'expand' => ['data.product']])->autoPagingIterator())
            ->filter(fn (StripePrice $price) => $price->product instanceof StripeProduct)
            // Tiered and package prices carry no `unit_amount`. The app only
            // models flat rates, and importing one as 0 would put a free plan on
            // the pricing page, so they are left at the provider.
            ->filter(fn (StripePrice $price) => $price->unit_amount !== null)
            ->groupBy(fn (StripePrice $price) => $price->product->id);

        $catalog = $prices->map(fn ($productPrices) => $this->catalogProduct(
            $productPrices->first()->product,
            $productPrices->map(fn (StripePrice $price) => new CatalogPriceData(
                providerPriceId: $price->id,
                currency: strtoupper($price->currency),
                amount: (int) $price->unit_amount,
                interval: $price->recurring?->interval,
                intervalCount: $price->recurring?->interval_count,
                active: $price->active,
            ))->values()->all(),
        ));

        // Active products with no price too, such as a plan that links to
        // sales: the push finds them by slug instead of creating another.
        foreach ($this->stripe->products->all(['limit' => 100, 'active' => true])->autoPagingIterator() as $product) {
            if (! $catalog->has($product->id)) {
                $catalog->put($product->id, $this->catalogProduct($product, []));
            }
        }

        return $catalog->values()->all();
    }

    /** @param  list<CatalogPriceData>  $prices */
    private function catalogProduct(StripeProduct $product, array $prices): CatalogProductData
    {
        return new CatalogProductData(
            providerProductId: $product->id,
            name: $product->name,
            description: $product->description,
            active: $product->active,
            slug: $product->metadata['slug'] ?? null,
            features: collect($product->marketing_features ?? [])
                ->pluck('name')
                ->filter(fn ($name) => is_string($name) && $name !== '')
                ->values()
                ->all(),
            prices: $prices,
        );
    }

    public function createProduct(Product $product): string
    {
        return $this->call('create a product', fn () => $this->stripe->products->create(array_filter([
            'name' => $product->name,
            'description' => $product->description ? strip_tags($product->description) : null,
            'active' => $product->is_active,
            'metadata' => ['slug' => $product->slug],
        ], fn ($value) => $value !== null))->id);
    }

    public function pushProductFeatures(Product $product): void
    {
        // Stripe wants an explicit empty list to clear features; null is a no-op.
        $this->call('update product features', fn () => $this->stripe->products->update($product->provider_product_id, [
            'marketing_features' => $this->marketingFeatures($product) ?? [],
        ]), $product->provider_product_id);
    }

    /**
     * The plan's feature list in the shape Stripe shows on its pricing table.
     *
     * Stripe takes at most 15, each at most 80 characters, and rejects the whole
     * product if either is exceeded, so the list is trimmed rather than risking
     * a product that cannot be created at all.
     *
     * @return list<array{name: string}>|null
     */
    private function marketingFeatures(Product $product): ?array
    {
        $features = collect($product->features ?? [])
            ->filter(fn ($feature) => is_string($feature) && trim($feature) !== '')
            ->map(fn (string $feature) => ['name' => mb_substr(trim($feature), 0, 80)])
            ->take(15)
            ->values()
            ->all();

        return $features ?: null;
    }

    public function createPrice(Price $price, string $providerProductId): string
    {
        $params = [
            'product' => $providerProductId,
            'currency' => strtolower($price->currency->value),
            'unit_amount' => $price->amount,
            'active' => $price->is_active,
        ];

        if ($price->interval) {
            $params['recurring'] = ['interval' => $price->interval, 'interval_count' => $price->interval_count ?? 1];
        }

        return $this->call('create a price', fn () => $this->stripe->prices->create($params)->id);
    }

    public function verifyAndParseWebhook(Request $request): WebhookData
    {
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $webhookSecret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            // A bad signature, or a body that is not an event: nothing proves it came from Stripe.
            throw new InvalidWebhookSignature('stripe');
        }

        $type = self::EVENT_MAP[$event->type] ?? null;

        return new WebhookData(
            type: $type,
            provider: 'stripe',
            providerEventId: $event->id,
            data: $type ? StripeEventMapper::map($type, $event->data->object->toArray()) : null,
            occurredAt: CarbonImmutable::createFromTimestamp($event->created),
        );
    }

    /**
     * Run one SDK call, turning a provider failure into the module's own. Only
     * Stripe's API errors are translated: a bug in how the SDK is called stays
     * a bug. The raw provider message is redacted before it is kept.
     *
     * @template T
     *
     * @param  \Closure(): T  $call
     * @return T
     */
    private function call(string $operation, \Closure $call, ?string $providerResourceId = null): mixed
    {
        try {
            return $call();
        } catch (ApiErrorException $e) {
            throw new GatewayOperationFailed(
                provider: 'stripe',
                operation: $operation,
                previous: new ProviderError(
                    sdkClass: $e::class,
                    message: $e->getMessage(),
                    providerCode: $e->getStripeCode(),
                    httpStatus: $e->getHttpStatus(),
                    requestId: $e->getRequestId(),
                ),
                providerResourceId: $providerResourceId,
            );
        }
    }
}
