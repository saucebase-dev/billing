<?php

namespace Modules\Billing\Tests\Unit;

use Modules\Billing\Models\Price;
use Tests\TestCase;

class PriceTest extends TestCase
{
    /** What the provider knows about is locked in the admin; what it doesn't stays editable. */
    public function test_a_price_is_managed_by_the_gateway_only_when_it_has_a_provider_id(): void
    {
        $this->assertTrue((new Price(['provider_price_id' => 'price_123']))->isManagedByGateway());
        $this->assertFalse((new Price(['provider_price_id' => null]))->isManagedByGateway());
    }
}
