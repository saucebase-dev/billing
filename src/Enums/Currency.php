<?php

namespace Modules\Billing\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Str;
use Modules\Billing\Settings\BillingSettings;

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

    /**
     * `€299.00 one-time`, `$9.00 / month`, `€90.00 every 3 months`. Plain
     * values, so a form's unsaved price row can use it too.
     */
    public function formatPrice(int $amountInMinorUnits, ?string $interval, ?int $intervalCount = 1): string
    {
        $amount = $this->formatAmount($amountInMinorUnits);

        return match (true) {
            $interval === null => $amount.' '.__('one-time'),
            ($intervalCount ?? 1) > 1 => $amount.' '.__('every :count :intervals', ['count' => $intervalCount, 'intervals' => __(Str::plural($interval))]),
            default => $amount.' / '.__($interval),
        };
    }
}
