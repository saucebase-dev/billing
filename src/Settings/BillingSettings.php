<?php

namespace Modules\Billing\Settings;

use Spatie\LaravelSettings\Settings;

class BillingSettings extends Settings
{
    /** Driver slug of the payment provider the merchant sells through. */
    public string $gateway;

    /** ISO 4217 code: the reporting currency and the default for new prices. */
    public string $currency;

    /**
     * Send buyers straight to the payment provider rather than showing the
     * module's own checkout page first.
     */
    public bool $redirect_to_gateway;

    /** Minutes of inactivity before a pending checkout is marked abandoned. */
    public int $checkout_abandon_after_minutes;

    /** Minutes before a pending checkout can no longer be completed. */
    public int $checkout_expire_after_minutes;

    public static function group(): string
    {
        return 'billing';
    }
}
