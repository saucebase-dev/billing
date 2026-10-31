<?php

namespace Modules\Billing\Tests\Unit;

use Modules\Billing\Enums\Currency;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    /** In the price's own currency, never a hardcoded dollar sign. */
    public function test_a_price_is_described_in_its_own_currency(): void
    {
        app()->setLocale('en');

        $this->assertSame('€299.00 one-time', Currency::EUR->formatPrice(29900, null));
        $this->assertSame('$9.00 / month', Currency::USD->formatPrice(900, 'month'));
        $this->assertSame('£90.00 / year', Currency::GBP->formatPrice(9000, 'year', 1));
    }

    /** A quarterly price is charged every 3 months, not every month. */
    public function test_a_multi_interval_price_says_how_often_it_is_charged(): void
    {
        app()->setLocale('en');

        $this->assertSame('€90.00 every 3 months', Currency::EUR->formatPrice(9000, 'month', 3));
    }
}
