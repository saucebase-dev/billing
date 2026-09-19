<?php

namespace Modules\Billing\Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Billing\Filament\Resources\Products\Pages\EditProduct;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Tests\TestCase;

/**
 * Once the provider knows a product, it owns what is charged and what the thing
 * is called. Disabling the inputs is a courtesy; these are the tests that a
 * submitted payload cannot get past it.
 */
class ProductAdminGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $admin->assignRole(Role::ADMIN);

        $this->actingAs($admin);
    }

    public function test_a_submitted_name_cannot_overwrite_a_gateway_managed_product(): void
    {
        $product = Product::factory()->create([
            'provider' => 'stripe',
            'provider_product_id' => 'prod_known',
            'name' => 'Pro',
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['name' => 'Renamed behind the provider'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Pro', $product->fresh()->name);
    }

    public function test_a_product_the_provider_does_not_know_is_still_editable(): void
    {
        $product = Product::factory()->create([
            'provider_product_id' => null,
            'name' => 'Draft',
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed', $product->fresh()->name);
    }

    public function test_a_gateway_managed_price_keeps_its_amount(): void
    {
        $product = Product::factory()->create([
            'provider' => 'stripe',
            'provider_product_id' => 'prod_known',
        ]);
        $price = Price::factory()->create([
            'product_id' => $product->id,
            'provider_price_id' => 'price_known',
            'amount' => 2900,
        ]);

        $component = Livewire::test(EditProduct::class, ['record' => $product->getKey()]);

        // Mutate the loaded repeater item rather than replacing it, so the rest
        // of the row's required fields survive.
        $state = $component->get('data');
        $state['prices'][array_key_first($state['prices'])]['amount'] = 1;

        $component->fillForm($state)->call('save')->assertHasNoFormErrors();

        $this->assertSame(2900, $price->fresh()->amount);
    }
}
