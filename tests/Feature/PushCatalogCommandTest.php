<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\PaymentGatewayManager;
use Tests\TestCase;

class PushCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_local_products_at_the_provider_and_reports_the_counts(): void
    {
        $product = Product::factory()->create(['provider' => null, 'provider_product_id' => null]);
        Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => null]);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('createProduct')->willReturn('prod_new');
        $gateway->method('createPrice')->willReturn('price_new');

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->artisan('billing:push-catalog')
            ->expectsOutputToContain('1 products, 1 prices created and 1 feature lists sent to stripe')
            ->assertSuccessful();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'provider_product_id' => 'prod_new']);
    }
}
