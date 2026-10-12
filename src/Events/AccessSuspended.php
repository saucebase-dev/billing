<?php

namespace Modules\Billing\Events;

/** The grace period ran out: the subscription no longer grants what it sells. */
class AccessSuspended extends SubscriptionEvent {}
