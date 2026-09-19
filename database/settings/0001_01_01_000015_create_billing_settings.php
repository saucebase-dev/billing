<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaults = [
            'billing.gateway' => 'stripe',
            'billing.currency' => 'EUR',
            'billing.redirect_to_gateway' => true,
            'billing.checkout_abandon_after_minutes' => 60,
            // Matches Stripe's own checkout session lifetime.
            'billing.checkout_expire_after_minutes' => 1440,
        ];

        foreach ($defaults as $key => $value) {
            if (! $this->migrator->exists($key)) {
                $this->migrator->add($key, $value);
            }
        }
    }
};
