<?php

namespace Modules\Billing\Filament;

use Filament\Contracts\Plugin;
use Saucebase\Core\Filament\ModulePlugin;

class BillingPlugin implements Plugin
{
    use ModulePlugin;

    public function getModuleName(): string
    {
        return 'Billing';
    }

    public function getId(): string
    {
        return 'billing';
    }

    public static function getNavigationGroupSort(): int
    {
        return 10;
    }
}
