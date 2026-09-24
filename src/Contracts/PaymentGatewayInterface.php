<?php

namespace Modules\Billing\Contracts;

use Illuminate\Http\Request;
use Modules\Billing\Data\CatalogProductData;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Data\CustomerData;
use Modules\Billing\Data\PaymentMethodData;
use Modules\Billing\Data\Webhook\CheckoutSessionData;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\WebhookData;
use Modules\Billing\Enums\CheckoutExpiry;
use Modules\Billing\Exceptions\GatewayOperationFailed;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;

interface PaymentGatewayInterface
{
    /** @return string The customer's ID at the provider. */
    public function createCustomer(CustomerData $data): string;

    /**
     * The provider's own view of a subscription, as it is right now.
     *
     * Events are not ordered against each other, so anything that must not act
     * on a stale picture — recovering a delinquent subscription, for one — asks
     * here instead of believing the event it just received.
     */
    public function retrieveSubscription(string $providerSubscriptionId): SubscriptionStateData;

    /**
     * A checkout read back when the buyer returns, in case its webhook has not
     * arrived. The module fulfils it only when it is `fulfillable`.
     */
    public function retrieveCheckoutSession(string $providerSessionId): CheckoutSessionData;

    /**
     * The payment method behind a reference this gateway put in its own data.
     *
     * A gateway must resolve every `paymentMethodReference` it emits, whatever
     * kind of ID it is — for Stripe a card, a subscription or a payment. The
     * module treats the reference as opaque. Null when there is no method.
     */
    public function resolvePaymentMethod(string $reference): ?PaymentMethodData;

    /**
     * Make a hosted checkout unpayable.
     *
     * Marking it finished here proves nothing while the provider's page still
     * accepts a card, so anything the app holds back for an open checkout — a
     * trial, for one — waits on this answer.
     *
     * Only a confirmed `Expired` may release what the checkout holds. Anything
     * the provider cannot confirm — unreachable, not found under these keys —
     * throws `GatewayOperationFailed`, and the hold stays.
     *
     * @throws GatewayOperationFailed
     */
    public function expireCheckoutSession(string $providerSessionId): CheckoutExpiry;

    public function createCheckoutSession(CheckoutData $data): CheckoutResultData;

    /** Stops renewal at the end of the paid period; returns when that is. */
    public function cancelSubscription(Subscription $subscription): ?\DateTimeInterface;

    public function resumeSubscription(Subscription $subscription): void;

    public function getManagementUrl(Customer $customer): string;

    /** Where the customer picks another plan for this subscription at the provider. */
    public function getPlanChangeUrl(Subscription $subscription): string;

    /**
     * Verify a delivery and translate it: `type` from the module's event list,
     * `data` the matching class (`WebhookEventType::dataClass()`). Anything else
     * the provider sends comes back with a null type and is acknowledged.
     */
    public function verifyAndParseWebhook(Request $request): WebhookData;

    /**
     * Every product the provider has, with its prices, whether active or not.
     *
     * @return list<CatalogProductData>
     */
    public function listCatalog(): array;

    /** @return string The product's ID at the provider. */
    public function createProduct(Product $product): string;

    /** @return string The price's ID at the provider. */
    public function createPrice(Price $price, string $providerProductId): string;

    /**
     * Send the product's feature list to the provider.
     *
     * The app owns what a plan promises, so this is one-directional: the
     * provider's copy exists only so its own pricing pages can show it.
     */
    public function pushProductFeatures(Product $product): void;
}
