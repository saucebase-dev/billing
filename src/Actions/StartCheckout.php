<?php

namespace Modules\Billing\Actions;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Data\AddressData;
use Modules\Billing\Data\CheckoutData;
use Modules\Billing\Data\CheckoutResultData;
use Modules\Billing\Data\CustomerData;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\PaymentGatewayManager;
use Modules\Billing\Services\PurchaseEligibility;
use Modules\Billing\Settings\BillingSettings;

/** Hand a pending checkout to the payment provider, creating the customer if needed. */
class StartCheckout
{
    public function __construct(
        private PaymentGatewayManager $manager,
        private PurchaseEligibility $eligibility,
    ) {}

    /**
     * @param  array<string, mixed>  $billingDetails
     */
    public function handle(CheckoutSession $session, BillingOwner $owner, User $buyer, string $successUrl, string $cancelUrl, array $billingDetails = [], ?string $coupon = null): CheckoutResultData
    {
        $customer = $this->ensureCustomer($owner, $buyer, billingDetails: $billingDetails);

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

        $this->eligibility->assertCanBuy($owner, $price);

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

            // Completed or expired while this request waited: handing it off
            // again would open a second payable page for a finished checkout.
            if ($session->status !== CheckoutSessionStatus::Pending) {
                throw new AuthorizationException;
            }

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

    /**
     * The owner's account at the provider, created on its first purchase.
     *
     * The buyer's name and email only seed a new account. After that the
     * account keeps its details unless the buyer types new ones in, so one
     * manager's checkout cannot replace a workspace's billing contact.
     *
     * @param  array<string, mixed>  $billingDetails
     */
    private function ensureCustomer(BillingOwner $owner, User $buyer, ?string $provider = null, array $billingDetails = []): Customer
    {
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

        $typed = array_filter([
            'name' => $billingDetails['name'] ?? null,
            'email' => $billingDetails['email'] ?? null,
            'phone' => $billingDetails['phone'] ?? null,
        ], fn ($v) => $v !== null);

        if ($addressData) {
            $typed['address'] = $addressData->toArray();
        }

        $customer = $owner->billingAccount();

        if ($customer) {
            if ($typed) {
                $customer->update($typed);
            }

            if (blank($customer->provider_customer_id)) {
                $customer->update(['provider_customer_id' => $this->manager->driver($customer->provider)->createCustomer(new CustomerData(
                    name: $customer->name ?? $buyer->name,
                    email: $customer->email ?? $buyer->email,
                    phone: $customer->phone,
                    address: $addressData,
                ))]);
            }

            return $customer;
        }

        $provider ??= $this->manager->getDefaultDriver();
        $details = ['name' => $buyer->name, 'email' => $buyer->email, 'phone' => null, ...$typed];

        $relation = $owner->billingCustomer();
        $customer = $relation->create([
            ...$details,
            'provider' => $provider,
            'provider_customer_id' => $this->manager->driver($provider)->createCustomer(new CustomerData(
                name: $details['name'],
                email: $details['email'],
                phone: $details['phone'],
                address: $addressData,
            )),
        ]);

        // The owner may have read its account already, as null.
        $relation->getParent()->setRelation('billingCustomer', $customer);

        return $customer;
    }
}
