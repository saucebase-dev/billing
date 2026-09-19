<?php

namespace Modules\Billing\Enums;

use Filament\Support\Contracts\HasLabel;

enum BillingScheme: string implements HasLabel
{
    case FlatRate = 'flat_rate';

    public function getLabel(): string
    {
        return match ($this) {
            self::FlatRate => __('Flat Rate'),
        };
    }
}
