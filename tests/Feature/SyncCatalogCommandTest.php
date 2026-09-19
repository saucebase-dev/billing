<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Data\CatalogPriceData;
use Modules\Billing\Data\CatalogProductData;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\PaymentGatewayManager;
use Tests\TestCase;

class SyncCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_the_catalog_and_reports_the_counts(): void
    {
        Price::factory()->create(['provider_price_id' => 'price_made_up']);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('listCatalog')->willReturn([
            new CatalogProductData(providerProductId: 'prod_pro', name: 'Pro', description: null, active: true, prices: [
                new CatalogPriceData(providerPriceId: 'price_pro', currency: 'EUR', amount: 2900, interval: 'month', intervalCount: 1, active: true),
            ]),
        ]);

        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('getDefaultDriver')->willReturn('stripe');
        $manager->method('driver')->willReturn($gateway);
        app()->instance(PaymentGatewayManager::class, $manager);

        $this->artisan('billing:sync-catalog')
            ->expectsOutputToContain('1 created, 0 updated')
            ->expectsOutputToContain('price_made_up')
            ->assertSuccessful();

        $this->assertDatabaseHas('prices', ['provider_price_id' => 'price_pro']);
    }
}
