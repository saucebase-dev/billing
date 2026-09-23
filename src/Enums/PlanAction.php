<?php

namespace Modules\Billing\Enums;

/**
 * What a pricing card's button does. Mirrored by the `PlanAction` type in
 * `resources/js/types/index.ts`; change one, change the other.
 */
enum PlanAction: string
{
    /** Checkout, paid from the start. */
    case Buy = 'buy';

    /** Checkout with the plan's free trial. */
    case Trial = 'trial';

    /** A guest signing up for the free plan. */
    case Signup = 'signup';

    /** A subscriber moving to this plan at the provider. */
    case Change = 'change';

    /** The plan's `cta_url`, such as talking to sales. */
    case Contact = 'contact';

    case Current = 'current';

    case Included = 'included';

    /** Buyable once the current subscription runs out. */
    case Later = 'later';

    case Unavailable = 'unavailable';
}
