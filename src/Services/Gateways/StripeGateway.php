<?php

namespace Modules\Billing\Services\Gateways;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Data\CatalogPriceData;
use Modules\Billing\Data\CatalogProductData;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Data\CustomerData;
use Modules\Billing\Data\PaymentMethodData;
use Modules\Billing\Data\PaymentMethodDetails;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\PaymentMethodType;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Price as StripePrice;
use Stripe\Product as StripeProduct;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StripeGateway implements PaymentGatewayInterface
{
    private const array EVENT_MAP = [
        'checkout.session.completed' => WebhookEventType::CheckoutCompleted,
        'checkout.session.async_payment_succeeded' => WebhookEventType::CheckoutCompleted,
        'customer.subscription.updated' => WebhookEventType::SubscriptionUpdated,
        'customer.subscription.deleted' => WebhookEventType::SubscriptionDeleted,
        'invoice.payment_succeeded' => WebhookEventType::PaymentSucceeded,
        'invoice.payment_failed' => WebhookEventType::PaymentFailed,
        'invoice.paid' => WebhookEventType::InvoicePaid,
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

        return $this->stripe->customers->create($params)->id;
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

        // Stripe rejects a session that both carries a discount and invites one,
        // so a resolved code wins and everyone else gets the field on Stripe's page.
        $promotionCode = $data->coupon ? $this->resolvePromotionCode($data->coupon) : null;

        if ($promotionCode !== null) {
            $params['discounts'] = [['promotion_code' => $promotionCode]];
        } else {
            $params['allow_promotion_codes'] = true;
        }

        $options = $data->idempotencyKey ? ['idempotency_key' => $data->idempotencyKey] : [];

        $session = $this->stripe->checkout->sessions->create($params, $options);

        return new CheckoutResultData(
            sessionId: $session->id,
            url: $session->url,
            provider: 'stripe',
        );
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
            $matches = $this->stripe->promotionCodes->all([
                'code' => $code,
                'active' => true,
                'limit' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not look up promotion code', ['error' => $e->getMessage()]);

            return null;
        }

        return $matches->data[0]->id ?? null;
    }

    public function cancelSubscription(Subscription $subscription, bool $immediately = false): ?\DateTimeInterface
    {
        if ($immediately) {
            $this->stripe->subscriptions->cancel($subscription->provider_subscription_id);

            return null;
        }

        $stripeSub = $this->stripe->subscriptions->update($subscription->provider_subscription_id, [
            'cancel_at_period_end' => true,
        ]);

        // Stripe sets `cancel_at` to the period end for this call; older API
        // versions only reported the period on the subscription itself.
        $endsAt = $stripeSub->cancel_at ?? $stripeSub->items->data[0]->current_period_end ?? null;

        return $endsAt ? Carbon::createFromTimestamp($endsAt) : null;
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        $this->stripe->subscriptions->update($subscription->provider_subscription_id, [
            'cancel_at_period_end' => false,
        ]);
    }

    public function getManagementUrl(Customer $customer): string
    {
        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customer->provider_customer_id,
            'return_url' => route('settings.billing'),
        ]);

        return $session->url;
    }

    public function resolvePaymentMethod(string $providerId): ?PaymentMethodData
    {
        $pmId = match (true) {
            str_starts_with($providerId, 'pm_') => $providerId,
            str_starts_with($providerId, 'sub_') => $this->stripe->subscriptions->retrieve($providerId)->default_payment_method,
            str_starts_with($providerId, 'pi_') => $this->stripe->paymentIntents->retrieve($providerId)->payment_method,
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

    public function retrieveCheckoutSession(string $sessionId): array
    {
        return $this->stripe->checkout->sessions->retrieve($sessionId)->toArray();
    }

    public function retrieveSubscription(string $subscriptionId): array
    {
        return $this->stripe->subscriptions->retrieve($subscriptionId)->toArray();
    }

    public function listCatalog(): array
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

        return $prices->map(function ($productPrices) {
            /** @var StripeProduct $product */
            $product = $productPrices->first()->product;

            return new CatalogProductData(
                providerProductId: $product->id,
                name: $product->name,
                description: $product->description,
                active: $product->active,
                features: collect($product->marketing_features ?? [])
                    ->pluck('name')
                    ->filter(fn ($name) => is_string($name) && $name !== '')
                    ->values()
                    ->all(),
                prices: $productPrices->map(fn (StripePrice $price) => new CatalogPriceData(
                    providerPriceId: $price->id,
                    currency: strtoupper($price->currency),
                    amount: (int) $price->unit_amount,
                    interval: $price->recurring?->interval,
                    intervalCount: $price->recurring?->interval_count,
                    active: $price->active,
                ))->values()->all(),
            );
        })->values()->all();
    }

    public function createProduct(Product $product): string
    {
        return $this->stripe->products->create(array_filter([
            'name' => $product->name,
            'description' => $product->description ? strip_tags($product->description) : null,
            'active' => $product->is_active,
        ], fn ($value) => $value !== null))->id;
    }

    public function pushProductFeatures(Product $product): void
    {
        // Stripe wants an explicit empty list to clear features; null is a no-op.
        $this->stripe->products->update($product->provider_product_id, [
            'marketing_features' => $this->marketingFeatures($product) ?? [],
        ]);
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

        return $this->stripe->prices->create($params)->id;
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
        } catch (SignatureVerificationException $e) {
            throw new HttpException(400, 'Invalid webhook signature: '.$e->getMessage());
        }

        $normalizedType = self::EVENT_MAP[$event->type] ?? null;

        return new WebhookData(
            type: $normalizedType,
            provider: 'stripe',
            providerEventId: $event->id,
            payload: $event->data->object->toArray(),
            occurredAt: CarbonImmutable::createFromTimestamp($event->created),
        );
    }
}
