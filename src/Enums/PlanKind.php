<?php

namespace Modules\Billing\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What buying a plan gives. The kind, not the price's interval, decides it:
 * a one-time price can be lifetime access or an onboarding call.
 */
enum PlanKind: string implements HasLabel
{
    /** Everyone's baseline. Never sold; at most one exists. */
    case Free = 'free';

    /** Grants its entitlements while a subscription to it is current. */
    case Subscription = 'subscription';

    /** Grants its entitlements for good, until fully refunded. */
    case Lifetime = 'lifetime';

    /** Paid once. Grants only what a module reads off a one-time payment, such as packages; else a service the app handles. */
    case OneOff = 'one_off';

    public function getLabel(): string
    {
        return match ($this) {
            self::Free => __('Free'),
            self::Subscription => __('Subscription'),
            self::Lifetime => __('Lifetime'),
            self::OneOff => __('One-off'),
        };
    }

    /** What the kind means, for admins: the form, the view page and the guide all show this. */
    public function description(): string
    {
        return match ($this) {
            self::Free => __('Every customer gets this plan\'s features automatically. It can\'t be bought, and you can only have one.'),
            self::Subscription => __('Customers pay on a recurring schedule and keep the features while the subscription is active.'),
            self::Lifetime => __('Customers pay once and keep the features unless the payment is fully refunded.'),
            self::OneOff => __('Customers pay once. Use it for services such as an onboarding call, or to sell packages. It gives no other features.'),
        };
    }

    /** Whether its prices renew; the others are sold with one-time prices. */
    public function isRecurring(): bool
    {
        return $this === self::Subscription;
    }
}
