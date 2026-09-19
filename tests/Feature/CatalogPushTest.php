<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\CatalogPush;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class CatalogPushTest extends TestCase
{
    use RefreshDatabase;

    /** @var PaymentGatewayInterface&MockObject */
    private PaymentGatewayInterface $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(PaymentGatewayInterface::class);

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($this->gateway);
        app()->instance(PaymentGatewayManager::class, $manager);
    }

    /** Plans drafted here before there was a provider account get created there once. */
    public function test_a_product_without_a_provider_id_is_created_at_the_provider(): void
    {
        $product = Product::factory()->create(['provider' => null, 'provider_product_id' => null, 'name' => 'Pro']);
        $price = Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => null, 'amount' => 2900]);

        $this->gateway->expects($this->once())->method('createProduct')
            ->with($this->callback(fn (Product $given) => $given->is($product)))
            ->willReturn('prod_new');
        $this->gateway->expects($this->once())->method('createPrice')
            ->with($this->callback(fn (Price $given) => $given->is($price)), 'prod_new')
            ->willReturn('price_new');

        $report = app(CatalogPush::class)->run();

        $this->assertSame(1, $report->products);
        $this->assertSame(1, $report->prices);

        $this->assertDatabaseHas('products', ['id' => $product->id, 'provider' => 'stripe', 'provider_product_id' => 'prod_new']);
        $this->assertDatabaseHas('prices', ['id' => $price->id, 'provider' => 'stripe', 'provider_price_id' => 'price_new']);
    }

    /** Features are the app's words about the plan, so the provider's copy is refreshed every run. */
    public function test_features_are_pushed_for_products_the_provider_already_has(): void
    {
        $product = Product::factory()->create([
            'provider' => 'stripe',
            'provider_product_id' => 'prod_known',
            'features' => ['1 project', '500MB storage'],
        ]);

        $this->gateway->expects($this->never())->method('createProduct');
        $this->gateway->expects($this->once())->method('pushProductFeatures')
            ->with($this->callback(fn (Product $given) => $given->is($product)));

        $report = app(CatalogPush::class)->run();

        $this->assertSame(0, $report->products);
        $this->assertSame(1, $report->features);
    }

    /** The provider's prices are immutable: what it already knows about is changed there, never from here. */
    public function test_rows_the_provider_already_knows_are_never_touched(): void
    {
        $synced = Product::factory()->create(['provider' => 'stripe', 'provider_product_id' => 'prod_known']);
        Price::factory()->create(['product_id' => $synced->id, 'provider_price_id' => 'price_known']);
        $newPrice = Price::factory()->create(['product_id' => $synced->id, 'provider_price_id' => null]);

        $this->gateway->expects($this->never())->method('createProduct');
        $this->gateway->expects($this->once())->method('createPrice')
            ->with($this->callback(fn (Price $given) => $given->is($newPrice)), 'prod_known')
            ->willReturn('price_added');

        $report = app(CatalogPush::class)->run();

        $this->assertSame(0, $report->products);
        $this->assertSame(1, $report->prices);
        $this->assertSame('price_added', $newPrice->fresh()->provider_price_id);
    }

    public function test_a_single_product_can_be_pushed_on_its_own(): void
    {
        $wanted = Product::factory()->create(['provider' => null, 'provider_product_id' => null]);
        Price::factory()->create(['product_id' => $wanted->id, 'provider_price_id' => null]);
        $other = Product::factory()->create(['provider' => null, 'provider_product_id' => null]);
        Price::factory()->create(['product_id' => $other->id, 'provider_price_id' => null]);

        $this->gateway->expects($this->once())->method('createProduct')
            ->with($this->callback(fn (Product $given) => $given->is($wanted)))
            ->willReturn('prod_wanted');
        $this->gateway->expects($this->once())->method('createPrice')->willReturn('price_wanted');

        $report = app(CatalogPush::class)->run($wanted);

        $this->assertSame(1, $report->products);
        $this->assertSame(1, $report->prices);
        $this->assertNull($other->fresh()->provider_product_id);
    }
}
