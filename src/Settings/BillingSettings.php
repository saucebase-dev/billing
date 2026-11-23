<?php

namespace Modules\Billing\Settings;

use Saucebase\Core\Settings\InstallSetting;
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

    /**
     * Slug of the settings section a buyer lands on after paying. Their invoices by
     * default; an app selling something to install sends them to it (`modules`, for Packages).
     */
    #[InstallSetting('Which settings section opens after a purchase?', rules: ['alpha_dash'], hint: 'billing shows the invoices; modules the packages to install.')]
    public string $after_checkout_section;

    /** Minutes of inactivity before a pending checkout is marked abandoned. */
    public int $checkout_abandon_after_minutes;

    /** Minutes before a pending checkout can no longer be completed. */
    public int $checkout_expire_after_minutes;

    /**
     * Days a subscription behind on payment keeps access before it is suspended.
     * Zero suspends at once. A window already running keeps the date it was given.
     */
    public int $grace_period_days;

    /** Whether starting a trial collects payment details. */
    public bool $trial_requires_payment_method;

    public static function group(): string
    {
        return 'billing';
    }
}
