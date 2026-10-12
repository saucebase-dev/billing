<?php

namespace Modules\Billing\Events;

/** The provider reported a change to a subscription (status, plan, period, cancellation) and it was applied; stale events are skipped. */
class SubscriptionUpdated extends SubscriptionEvent {}
