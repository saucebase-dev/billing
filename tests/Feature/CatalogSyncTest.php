<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Data\CatalogPriceData;
use Modules\Billing\Data\CatalogProductData;
use Modules\Billing\Models\Price;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\CatalogSync;
use Modules\Billing\Services\PaymentGatewayManager;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class CatalogSyncTest extends TestCase
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

    /**
     * @param  list<CatalogPriceData>  $prices
     */
    private function catalogProduct(string $id, string $name, array $prices, bool $active = true, array $features = []): CatalogProductData
    {
        return new CatalogProductData(providerProductId: $id, name: $name, description: null, active: $active, prices: $prices, features: $features);
    }

    private function catalogPrice(string $id, int $amount, ?string $interval = 'month', bool $active = true): CatalogPriceData
    {
        return new CatalogPriceData(providerPriceId: $id, currency: 'EUR', amount: $amount, interval: $interval, intervalCount: 1, active: $active);
    }

    /** Nothing reaches the pricing page until the admin has looked at it. */
    public function test_a_product_new_to_the_app_is_imported_hidden(): void
    {
        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_pro', 'Pro', [$this->catalogPrice('price_pro_month', 2900)]),
        ]);

        $report = app(CatalogSync::class)->run();

        $this->assertSame(1, $report->created);

        $product = Product::where('provider', 'stripe')->where('provider_product_id', 'prod_pro')->first();
        $this->assertNotNull($product);
        $this->assertSame('Pro', $product->name);
        $this->assertFalse($product->is_visible);
        $this->assertTrue($product->is_active);

        $this->assertDatabaseHas('prices', [
            'product_id' => $product->id,
            'provider' => 'stripe',
            'provider_price_id' => 'price_pro_month',
            'amount' => 2900,
            'currency' => 'EUR',
            'interval' => 'month',
            'is_active' => true,
        ]);
    }

    /** A plan imported for the first time arrives with whatever the provider advertises. */
    public function test_a_new_product_takes_the_provider_feature_list(): void
    {
        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_pro', 'Pro', [$this->catalogPrice('price_pro_month', 2900)], features: ['10 projects', 'Priority support']),
        ]);

        app(CatalogSync::class)->run();

        $this->assertSame(
            ['10 projects', 'Priority support'],
            Product::where('provider_product_id', 'prod_pro')->value('features'),
        );
    }

    /** Once here, the words are the app's: the push sends them back, so a re-read would fight it. */
    public function test_an_existing_product_keeps_the_features_written_here(): void
    {
        $product = Product::factory()->create([
            'provider' => 'stripe',
            'provider_product_id' => 'prod_pro',
            'features' => ['Our own list'],
        ]);

        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_pro', 'Pro', [$this->catalogPrice('price_pro_month', 2900)], features: ['Stale provider copy']),
        ]);

        app(CatalogSync::class)->run();

        $this->assertSame(['Our own list'], $product->fresh()->features);
    }

    /** A batch of imports must not all land on the same rank for the admin to untangle. */
    public function test_imported_products_are_appended_to_the_existing_order(): void
    {
        Product::factory()->create(['provider' => 'stripe', 'provider_product_id' => 'prod_old', 'display_order' => 7]);

        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_a', 'A', [$this->catalogPrice('price_a', 100)]),
            $this->catalogProduct('prod_b', 'B', [$this->catalogPrice('price_b', 200)]),
        ]);

        app(CatalogSync::class)->run();

        $this->assertSame(8, Product::where('provider_product_id', 'prod_a')->value('display_order'));
        $this->assertSame(9, Product::where('provider_product_id', 'prod_b')->value('display_order'));
    }

    /** The provider owns what it charges; the app owns how it is shown. */
    public function test_an_existing_product_takes_the_gateway_fields_and_keeps_its_own(): void
    {
        $product = Product::factory()->create([
            'provider' => 'stripe',
            'provider_product_id' => 'prod_pro',
            'name' => 'Old name',
            'slug' => 'pro',
            'is_visible' => true,
            'is_highlighted' => true,
            'display_order' => 3,
            'description' => 'Our own words',
        ]);
        $price = Price::factory()->create([
            'product_id' => $product->id,
            'provider_price_id' => 'price_pro_month',
            'amount' => 1900,
        ]);

        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_pro', 'Pro', [$this->catalogPrice('price_pro_month', 2900)]),
        ]);

        $report = app(CatalogSync::class)->run();

        $this->assertSame(0, $report->created);
        $this->assertSame(1, $report->updated);

        $product->refresh();
        $this->assertSame('Pro', $product->name);
        $this->assertSame('pro', $product->slug);
        $this->assertTrue($product->is_visible);
        $this->assertTrue($product->is_highlighted);
        $this->assertSame(3, $product->display_order);
        $this->assertSame('Our own words', $product->description);

        $this->assertSame(2900, $price->fresh()->amount);
        $this->assertDatabaseCount('prices', 1);
    }

    /** A plan retired before this app ever saw it is history, not catalogue. */
    public function test_a_product_archived_at_the_provider_and_unknown_here_is_not_imported(): void
    {
        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_retired', 'Retired', [$this->catalogPrice('price_retired', 900, active: false)], active: false),
        ]);

        $report = app(CatalogSync::class)->run();

        $this->assertSame(0, $report->created);
        $this->assertSame(1, $report->skipped);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('prices', 0);
    }

    /** Subscriptions and payments point at these rows, so archiving never deletes. */
    public function test_a_product_archived_at_the_provider_is_deactivated_not_deleted(): void
    {
        $product = Product::factory()->create(['provider' => 'stripe', 'provider_product_id' => 'prod_old']);
        $price = Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => 'price_old']);

        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_old', 'Old', [$this->catalogPrice('price_old', 900, active: false)], active: false),
        ]);

        app(CatalogSync::class)->run();

        $this->assertFalse($product->fresh()->is_active);
        $this->assertFalse($price->fresh()->is_active);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('prices', 1);
    }

    /** Deleting is the app's decision; the provider's fields still land on the trashed row. */
    public function test_a_product_deleted_locally_is_updated_in_place_and_stays_deleted(): void
    {
        $product = Product::factory()->create(['provider' => 'stripe', 'provider_product_id' => 'prod_pro', 'name' => 'Old']);
        $product->delete();

        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_pro', 'Pro', [$this->catalogPrice('price_pro_month', 2900)]),
        ]);

        $report = app(CatalogSync::class)->run();

        $this->assertSame(1, $report->updated);
        $this->assertDatabaseCount('products', 1);
        $this->assertSame('Pro', $product->fresh()->name);
        $this->assertTrue($product->fresh()->trashed());
    }

    /** Hiding the delete control is UI only; the row itself has to refuse. */
    public function test_a_price_the_provider_knows_cannot_be_deleted_here(): void
    {
        $product = Product::factory()->create(['provider' => 'stripe', 'provider_product_id' => 'prod_pro']);
        $synced = Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => 'price_pro_month']);
        $local = Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => null]);

        $this->expectException(\RuntimeException::class);

        try {
            $synced->delete();
        } finally {
            $this->assertDatabaseHas('prices', ['id' => $synced->id]);
            $local->delete();
            $this->assertDatabaseMissing('prices', ['id' => $local->id]);
        }
    }

    /** Demo and hand-made prices have nothing at the provider; they are left alone and named. */
    public function test_a_local_only_price_is_left_alone_and_reported(): void
    {
        $product = Product::factory()->create(['provider' => 'stripe', 'provider_product_id' => 'prod_pro']);
        $synced = Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => 'price_pro_month']);
        $local = Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => 'price_made_up', 'amount' => 100]);

        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_pro', 'Pro', [$this->catalogPrice('price_pro_month', 2900)]),
        ]);

        $report = app(CatalogSync::class)->run();

        $this->assertSame(100, $local->fresh()->amount);
        $this->assertTrue($local->fresh()->is_active);
        $this->assertSame(['price_made_up'], $report->localOnly);
        $this->assertSame(2900, $synced->fresh()->amount);
    }

    /** Never Lifetime by accident: a one-time product grants nothing until the admin says so. */
    public function test_a_product_with_only_one_time_prices_is_imported_as_a_one_off(): void
    {
        $this->gateway->method('listCatalog')->willReturn([
            $this->catalogProduct('prod_setup', 'Onboarding', [$this->catalogPrice('price_setup', 49900, null)]),
            $this->catalogProduct('prod_pro', 'Pro', [$this->catalogPrice('price_pro_month', 2900)]),
        ]);

        app(CatalogSync::class)->run();

        $this->assertSame(PlanKind::OneOff, Product::where('provider_product_id', 'prod_setup')->first()->kind);
        $this->assertSame(PlanKind::Subscription, Product::where('provider_product_id', 'prod_pro')->first()->kind);
    }

    /** The slug is the plan's identity in code; a rename at the provider must not move it. */
    public function test_a_renamed_product_keeps_its_slug(): void
    {
        $this->gateway->method('listCatalog')->willReturnOnConsecutiveCalls(
            [$this->catalogProduct('prod_pro', 'Pro', [$this->catalogPrice('price_pro_month', 2900)])],
            [$this->catalogProduct('prod_pro', 'Professional', [$this->catalogPrice('price_pro_month', 2900)])],
        );

        app(CatalogSync::class)->run();
        $slug = Product::where('provider_product_id', 'prod_pro')->value('slug');
        app(CatalogSync::class)->run();

        $this->assertSame($slug, Product::where('provider_product_id', 'prod_pro')->value('slug'));
    }

    /** Nothing to sell, so nothing to import; the push still finds it by slug. */
    public function test_a_product_with_no_prices_is_not_imported(): void
    {
        $this->gateway->method('listCatalog')->willReturn([$this->catalogProduct('prod_sales', 'Enterprise', [])]);

        $report = app(CatalogSync::class)->run();

        $this->assertSame(1, $report->skipped);
        $this->assertDatabaseCount('products', 0);
    }
}
