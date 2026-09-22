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

    /** Paid once, grants nothing: a service or anything the app handles itself. */
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

    /** Whether its prices renew; the others are sold with one-time prices. */
    public function isRecurring(): bool
    {
        return $this === self::Subscription;
    }
}
