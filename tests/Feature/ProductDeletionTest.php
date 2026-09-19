<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;

class ProductDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** Deleting in the admin is a hide, not a loss: subscriptions still bill on the price. */
    public function test_deleting_a_product_is_soft_and_keeps_its_prices(): void
    {
        $product = Product::factory()->create();
        $price = Price::factory()->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertSoftDeleted($product);
        $this->assertDatabaseHas('prices', ['id' => $price->id]);
    }

    /**
     * Products cascade to prices, so a force delete would reach subscriptions
     * too. Refusing is the only answer that cannot lose a paying customer.
     */
    public function test_a_product_cannot_be_force_deleted_while_a_subscription_bills_on_it(): void
    {
        $product = Product::factory()->create();
        // No provider ID, so the guard below it stands aside and the database
        // constraint is what has to refuse.
        $price = Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => null]);
        $subscription = Subscription::factory()->create(['price_id' => $price->id]);

        $this->expectException(QueryException::class);

        try {
            $product->forceDelete();
        } finally {
            $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id]);
            $this->assertDatabaseHas('prices', ['id' => $price->id]);
        }
    }

    /**
     * The database cascade from products to prices does not fire the price's own
     * deleting guard, so without this the provider would quietly be desynced.
     */
    public function test_a_product_cannot_be_force_deleted_while_the_provider_knows_its_prices(): void
    {
        $product = Product::factory()->create(['provider' => 'stripe']);
        $price = Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => 'price_known']);

        $this->expectException(\RuntimeException::class);

        try {
            $product->forceDelete();
        } finally {
            $this->assertDatabaseHas('prices', ['id' => $price->id]);
            $this->assertDatabaseHas('products', ['id' => $product->id]);
        }
    }

    /** Nothing is billing on it, so the admin may actually be rid of it. */
    public function test_a_product_nothing_bills_on_can_be_force_deleted(): void
    {
        $product = Product::factory()->create();
        $price = Price::factory()->create(['product_id' => $product->id, 'provider_price_id' => null]);

        $product->forceDelete();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('prices', ['id' => $price->id]);
    }
}
