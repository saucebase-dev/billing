<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Where buyers already landed, so an installed app changes nothing.
        if (! $this->migrator->exists('billing.after_checkout_section')) {
            $this->migrator->add('billing.after_checkout_section', 'billing');
        }
    }
};
