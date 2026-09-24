<?php

namespace Modules\Billing\Enums;

use Modules\Billing\Data\Webhook\CheckoutSessionData;
use Modules\Billing\Data\Webhook\CustomerDefaultsData;
use Modules\Billing\Data\Webhook\InvoiceData;
use Modules\Billing\Data\Webhook\InvoicePaymentData;
use Modules\Billing\Data\Webhook\PaymentMethodChangeData;
use Modules\Billing\Data\Webhook\RefundData;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Data\Webhook\WebhookEventData;

enum WebhookEventType: string
{
    case CheckoutCompleted = 'checkout.completed';
    case SubscriptionUpdated = 'subscription.updated';
    case SubscriptionDeleted = 'subscription.deleted';
    case SubscriptionTrialWillEnd = 'subscription.trial_will_end';
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case InvoicePaid = 'invoice.paid';
    case PaymentRefunded = 'payment.refunded';
    case PaymentMethodAttached = 'payment_method.attached';
    case PaymentMethodDetached = 'payment_method.detached';
    case CustomerUpdated = 'customer.updated';

    /**
     * The data every gateway must build for this event.
     *
     * @return class-string<WebhookEventData>
     */
    public function dataClass(): string
    {
        return match ($this) {
            self::CheckoutCompleted => CheckoutSessionData::class,
            self::SubscriptionUpdated, self::SubscriptionDeleted, self::SubscriptionTrialWillEnd => SubscriptionStateData::class,
            self::PaymentSucceeded, self::PaymentFailed => InvoicePaymentData::class,
            self::InvoicePaid => InvoiceData::class,
            self::PaymentRefunded => RefundData::class,
            self::PaymentMethodAttached, self::PaymentMethodDetached => PaymentMethodChangeData::class,
            self::CustomerUpdated => CustomerDefaultsData::class,
        };
    }
}
