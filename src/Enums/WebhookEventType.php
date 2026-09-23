<?php

namespace Modules\Billing\Enums;

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
}
