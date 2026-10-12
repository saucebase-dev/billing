<?php

namespace Modules\Billing\Events;

/** A payment was refunded in full, which ends what it bought. A partial refund announces nothing. */
class PaymentRefunded extends PaymentEvent {}
