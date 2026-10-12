<?php

namespace Modules\Billing\Events;

/** A payment failed and the subscription now has a deadline to fix it by. */
class GraceStarted extends SubscriptionEvent {}
