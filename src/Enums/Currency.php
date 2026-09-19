<?php

namespace Modules\Billing\Enums;

use Filament\Support\Contracts\HasLabel;
use Modules\Billing\Settings\BillingSettings;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum Currency: string implements HasLabel
{
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case BRL = 'BRL';

    public function getLabel(): string
    {
        return $this->value;
    }

    /** The merchant's reporting currency, from the billing settings. */
    public static function default(): self
    {
        return self::from(app(BillingSettings::class)->currency);
    }

    public function formatAmount(int $amountInMinorUnits): string
    {
        $formatter = new \NumberFormatter(app()->getLocale(), \NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($amountInMinorUnits / 100, $this->value);
    }
}
