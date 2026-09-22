<?php

namespace Modules\Billing\Tests\Feature;

use App\Enums\Role;
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
        $admin->assignRole(Role::ADMIN);

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

    public function test_an_entitlement_key_must_be_snake_case(): void
    {
        $plan = Product::factory()->create();

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->fillForm(['entitlements' => [['key' => 'Export Data', 'type' => 'feature']]])
            ->call('save')
            ->assertHasFormErrors();

        $this->assertNull($plan->fresh()->entitlements);
    }
}
