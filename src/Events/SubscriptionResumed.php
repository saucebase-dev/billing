<?php

namespace Modules\Billing\Events;

/** The customer undid a scheduled cancellation, so the subscription renews again. */
class SubscriptionResumed extends SubscriptionEvent {}
