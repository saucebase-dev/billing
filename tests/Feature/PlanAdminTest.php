<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Filament\Resources\Products\Pages\EditProduct;
use Modules\Billing\Models\Product;
use Tests\TestCase;

/**
 * The admin edits a plan's kind, what a lifetime plan replaces, and its
 * entitlements; the form writes them in the shape the rest of billing reads.
 */
class PlanAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);
    }

    public function test_an_admin_sets_a_plans_kind_and_what_it_replaces(): void
    {
        $pro = Product::factory()->create();
        $plan = Product::factory()->create();

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->fillForm(['kind' => PlanKind::Lifetime->value, 'replaces_product_id' => $pro->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(PlanKind::Lifetime, $plan->fresh()->kind);
        $this->assertSame($pro->id, $plan->fresh()->replaces_product_id);
    }

    /** Editing shows exactly the stored entitlements, and saving untouched keeps them. */
    public function test_an_existing_plans_entitlements_load_one_row_each(): void
    {
        $stored = ['features' => ['webhooks' => true], 'limits' => ['projects' => null, 'api_requests' => 1000]];
        $plan = Product::factory()->create(['entitlements' => $stored]);

        $page = Livewire::test(EditProduct::class, ['record' => $plan->getKey()]);

        $this->assertSame(
            ['webhooks', 'projects', 'api_requests'],
            array_column(array_values($page->get('data.entitlements')), 'key'),
        );

        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame($stored, $plan->fresh()->entitlements);
    }

    /** One row per entitlement in the form; the stored shape the resolver reads. */
    public function test_entitlements_are_stored_as_features_and_limits(): void
    {
        $plan = Product::factory()->create();

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->fillForm(['entitlements' => [
                ['key' => 'exports', 'type' => 'feature'],
                ['key' => 'projects', 'type' => 'limit', 'unlimited' => false, 'limit' => 10],
                ['key' => 'members', 'type' => 'limit', 'unlimited' => true],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            ['features' => ['exports' => true], 'limits' => ['projects' => 10, 'members' => null]],
            $plan->fresh()->entitlements,
        );
    }

    public function test_an_entitlement_key_may_be_a_package_name(): void
    {
        $plan = Product::factory()->create();

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->fillForm(['entitlements' => [['key' => 'packages:saucebase/api-tokens', 'type' => 'feature'], ['key' => 'export data', 'type' => 'feature']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($plan->fresh()->entitlements()->allows('packages:saucebase/api-tokens'));
        $this->assertTrue($plan->fresh()->entitlements()->allows('export data'));
    }

    public function test_an_entitlement_key_has_no_edge_spaces(): void
    {
        $plan = Product::factory()->create();

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->fillForm(['entitlements' => [['key' => 'export ', 'type' => 'feature']]])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertNull($plan->fresh()->entitlements);
    }
}
