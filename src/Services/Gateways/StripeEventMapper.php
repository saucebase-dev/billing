<?php

namespace Modules\Billing\Services\Gateways;

use Carbon\Carbon;
use Modules\Billing\Data\Webhook\CheckoutSessionData;
use Modules\Billing\Data\Webhook\CustomerDefaultsData;
use Modules\Billing\Data\Webhook\InvoiceData;
use Modules\Billing\Data\Webhook\InvoicePaymentData;
use Modules\Billing\Data\Webhook\PaymentMethodChangeData;
use Modules\Billing\Data\Webhook\RefundData;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\Webhook\WebhookEventData;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Spatie\LaravelData\Optional;

/**
 * Stripe's payloads, in the module's words. Everything the module knows about
 * Stripe's shapes — status names, where the period lives this API version, how
 * a cancellation is spelled — is here, so nothing past the gateway reads JSON.
 * Pure: no API calls.
 */
class StripeEventMapper
{
    /** @param  array<string, mixed>  $object  The event's `data.object`. */
    public static function map(WebhookEventType $type, array $object): WebhookEventData
    {
        return match ($type) {
            // The event only fires once the session is complete; its payment may still be pending.
            WebhookEventType::CheckoutCompleted => self::checkoutSession(['status' => 'complete', ...$object]),
            WebhookEventType::SubscriptionUpdated, WebhookEventType::SubscriptionDeleted, WebhookEventType::SubscriptionTrialWillEnd => self::subscription($object),
            WebhookEventType::PaymentSucceeded => self::invoicePayment($object, 'amount_paid'),
            WebhookEventType::PaymentFailed => self::invoicePayment($object, 'amount_due'),
            WebhookEventType::InvoicePaid => self::invoice($object),
            WebhookEventType::PaymentRefunded => new RefundData(
                providerCustomerId: self::string($object, 'customer'),
                providerPaymentId: self::string($object, 'payment_intent'),
                amountRefunded: $object['amount_refunded'] ?? null,
                fullyRefunded: ($object['refunded'] ?? false) === true,
            ),
            WebhookEventType::PaymentMethodAttached, WebhookEventType::PaymentMethodDetached => new PaymentMethodChangeData(
                providerPaymentMethodId: self::string($object, 'id'),
                paymentMethodReference: self::string($object, 'id'),
                providerCustomerId: self::string($object, 'customer'),
            ),
            WebhookEventType::CustomerUpdated => new CustomerDefaultsData(
                providerCustomerId: (string) $object['id'],
                defaultPaymentMethodReference: array_key_exists('invoice_settings', $object) && is_array($object['invoice_settings']) && array_key_exists('default_payment_method', $object['invoice_settings'])
                    ? self::string($object['invoice_settings'], 'default_payment_method')
                    : new Optional,
            ),
        };
    }

    /**
     * A checkout session, from a webhook or read back on the buyer's return.
     *
     * `unpaid` on a complete session is a delayed payment method that has not
     * settled; Stripe sends a second event once it does.
     *
     * @param  array<string, mixed>  $session
     */
    public static function checkoutSession(array $session): CheckoutSessionData
    {
        $subscription = self::string($session, 'subscription');
        $paymentIntent = self::string($session, 'payment_intent');

        return new CheckoutSessionData(
            sessionId: (string) $session['id'],
            fulfillable: ($session['status'] ?? null) === 'complete'
                && in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true),
            providerSubscriptionId: $subscription,
            providerPaymentId: $paymentIntent,
            // `resolvePaymentMethod()` reads the card off either.
            paymentMethodReference: $subscription ?? $paymentIntent,
            currency: self::currency($session),
            amount: (int) ($session['amount_total'] ?? 0),
        );
    }

    /** @param  array<string, mixed>  $subscription */
    public static function subscription(array $subscription): SubscriptionStateData
    {
        // Since API 2025-03-31 the period lives on the item; endpoints pinned
        // earlier still send it on the subscription. There is one item.
        $item = $subscription['items']['data'][0] ?? [];
        $periodEnd = $item['current_period_end'] ?? $subscription['current_period_end'] ?? null;

        [$cancels, $endsAt] = match (true) {
            ($subscription['cancel_at_period_end'] ?? false) === true => [true, $periodEnd ?? $subscription['cancel_at'] ?? null],
            ! empty($subscription['cancel_at']) => [true, $subscription['cancel_at']],
            array_key_exists('cancel_at_period_end', $subscription) => [false, null],
            default => [new Optional, null],
        };

        return new SubscriptionStateData(
            providerSubscriptionId: (string) $subscription['id'],
            providerCustomerId: self::string($subscription, 'customer'),
            status: match ($subscription['status'] ?? null) {
                'active', 'trialing' => SubscriptionStatus::Active,
                'past_due' => SubscriptionStatus::PastDue,
                'unpaid' => SubscriptionStatus::Suspended,
                'canceled', 'incomplete_expired' => SubscriptionStatus::Cancelled,
                'incomplete', 'paused' => SubscriptionStatus::Pending,
                default => null,
            },
            trialStartsAt: self::date($subscription['trial_start'] ?? null),
            trialEndsAt: self::date($subscription['trial_end'] ?? null),
            periodStartsAt: self::date($item['current_period_start'] ?? $subscription['current_period_start'] ?? null),
            periodEndsAt: self::date($periodEnd),
            providerPriceId: $item['price']['id'] ?? null,
            paymentMethodReference: array_key_exists('default_payment_method', $subscription)
                ? self::string($subscription, 'default_payment_method')
                : new Optional,
            cancellationScheduled: $cancels,
            endsAt: self::date($endsAt),
        );
    }

    /** @param  array<string, mixed>  $invoice */
    private static function invoicePayment(array $invoice, string $amountKey): InvoicePaymentData
    {
        return new InvoicePaymentData(
            providerCustomerId: self::string($invoice, 'customer'),
            providerSubscriptionId: self::invoiceSubscription($invoice),
            // ponytail: falls back to the invoice ID when there is no intent; sc-788 settles payment identity.
            providerPaymentId: self::string($invoice, 'payment_intent') ?? (string) $invoice['id'],
            paymentMethodReference: self::string($invoice, 'default_payment_method'),
            currency: self::currency($invoice),
            amount: (int) ($invoice[$amountKey] ?? 0),
        );
    }

    /** @param  array<string, mixed>  $invoice */
    private static function invoice(array $invoice): InvoiceData
    {
        $periods = array_column($invoice['lines']['data'] ?? [], 'period');

        return new InvoiceData(
            providerInvoiceId: (string) $invoice['id'],
            providerCustomerId: self::string($invoice, 'customer'),
            providerSubscriptionId: self::invoiceSubscription($invoice),
            number: $invoice['number'] ?? null,
            currency: self::currency($invoice),
            subtotal: (int) ($invoice['subtotal'] ?? 0),
            tax: (int) ($invoice['tax'] ?? 0),
            total: (int) ($invoice['total'] ?? 0),
            hostedUrl: $invoice['hosted_invoice_url'] ?? null,
            pdfUrl: $invoice['invoice_pdf'] ?? null,
            periodStartsAt: $periods ? self::date(min(array_column($periods, 'start'))) : null,
            periodEndsAt: $periods ? self::date(max(array_column($periods, 'end'))) : null,
        );
    }

    /**
     * Newer API versions nest the subscription under `parent`.
     *
     * @param  array<string, mixed>  $invoice
     */
    private static function invoiceSubscription(array $invoice): ?string
    {
        return self::string($invoice, 'subscription')
            ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
    }

    /** @param  array<string, mixed>  $object */
    private static function currency(array $object): Currency
    {
        return Currency::tryFrom(strtoupper($object['currency'] ?? '')) ?? Currency::default();
    }

    /**
     * A non-empty string, or null. Stripe sends an ID or an expanded object;
     * the module only ever asks for the ID.
     *
     * @param  array<string, mixed>  $object
     */
    private static function string(array $object, string $key): ?string
    {
        $value = $object[$key] ?? null;

        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function date(mixed $timestamp): ?Carbon
    {
        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
