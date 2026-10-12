<?php

namespace Modules\Billing\Events;

/** A payment went through: a checkout's first payment or one-time purchase, or a renewal. */
class PaymentSucceeded extends PaymentEvent {}
