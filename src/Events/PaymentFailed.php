<?php

namespace Modules\Billing\Events;

/** The provider could not collect a renewal; the subscription's own update moves its status. */
class PaymentFailed extends PaymentEvent {}
