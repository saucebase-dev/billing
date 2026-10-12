<?php

namespace Modules\Billing\Enums;

/**
 * What the provider confirmed about a checkout it was asked to expire. Anything
 * it could not confirm is an exception, never one of these.
 */
enum CheckoutExpiry
{
    /** Can no longer be paid: whatever it held can be released. */
    case Expired;

    /** Paid or otherwise finished: whatever it held stays held. */
    case Completed;
}
