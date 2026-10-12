<?php

namespace Modules\Billing\Enums;

/**
 * Why an owner may not buy a price. Checkout turns it into an error message,
 * the pricing page into a button; both come from the same decision.
 */
enum PurchaseRefusal: string
{
    /** The price or its plan is not on sale: inactive, not pushed, or the wrong kind of price for the plan. */
    case Unavailable = 'unavailable';

    /** The free plan: everyone already has it. */
    case NotForSale = 'not_for_sale';

    /** The owner already holds this plan. */
    case Current = 'current';

    /** A lifetime plan the owner holds replaces this one. */
    case Included = 'included';

    /** The owner's subscription is ending, replaced by lifetime; another plan waits until it has. */
    case AfterCurrentEnds = 'after_current_ends';

    /** The owner already subscribes to another plan and changes it at the provider. */
    case ChangeInstead = 'change_instead';

    public function message(): string
    {
        return match ($this) {
            self::Unavailable => __('This plan is not available.'),
            self::NotForSale => __('The free plan is already yours.'),
            self::Current => __('You already have this plan.'),
            self::Included => __('Your lifetime plan already includes this.'),
            self::AfterCurrentEnds => __('You can choose another plan once your current subscription ends.'),
            self::ChangeInstead => __('You already have a plan. Change it from your billing settings.'),
        };
    }
}
