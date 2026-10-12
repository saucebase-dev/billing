<?php

use Modules\Billing\Events\AccessSuspended;
use Modules\Billing\Events\GraceStarted;
use Modules\Billing\Events\PaymentSucceeded;
use Modules\Billing\Events\SubscriptionCancelled;
use Modules\Billing\Events\SubscriptionCreated;
use Modules\Billing\Events\SubscriptionResumed;
use Modules\Billing\Events\SubscriptionUpdated;
use Modules\Billing\Events\TrialEnding;
use Modules\Billing\Notifications\AccessSuspendedNotification;
use Modules\Billing\Notifications\GraceStartedNotification;
use Modules\Billing\Notifications\PaymentSucceededNotification;
use Modules\Billing\Notifications\SubscriptionCancelledNotification;
use Modules\Billing\Notifications\SubscriptionCreatedNotification;
use Modules\Billing\Notifications\SubscriptionResumedNotification;
use Modules\Billing\Notifications\SubscriptionUpdatedNotification;
use Modules\Billing\Notifications\TrialEndingNotification;

// Everything a merchant can decide lives in BillingSettings (admin → Settings →
// Billing). Provider credentials stay in config/services.php.
return [
    'name' => 'Billing',

    // The mail each event sends to the customer's owner. Remove an entry to stop it, map
    // a subclass to change it, or add your own.
    'notifications' => [
        SubscriptionCreated::class => [SubscriptionCreatedNotification::class],
        SubscriptionUpdated::class => [SubscriptionUpdatedNotification::class],
        SubscriptionCancelled::class => [SubscriptionCancelledNotification::class],
        SubscriptionResumed::class => [SubscriptionResumedNotification::class],
        TrialEnding::class => [TrialEndingNotification::class],
        GraceStarted::class => [GraceStartedNotification::class],
        AccessSuspended::class => [AccessSuspendedNotification::class],
        PaymentSucceeded::class => [PaymentSucceededNotification::class],
    ],
];
