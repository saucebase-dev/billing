<?php

namespace Modules\Billing\Enums;

enum CheckoutSessionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
    case Expired = 'expired';
}
