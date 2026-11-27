<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Product;
use Tests\TestCase;

class ProductFactoryTest extends TestCase
{
    use RefreshDatabase;

    /** Two random words collided on the unique slug in CI (billing#44), and one unique word runs out after about 180 SKUs. */
    public function test_many_products_get_their_own_slug_and_sku(): void
    {
        Product::factory()->count(500)->create();

        $this->assertSame(500, Product::query()->distinct()->count('slug'));
        $this->assertSame(500, Product::query()->distinct()->count('sku'));
    }
}
