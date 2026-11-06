<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Filament\Resources\Products\Pages\EditProduct;
use Modules\Billing\Filament\Resources\Products\Schemas\ProductForm;
use Modules\Billing\Models\Product;
use Saucebase\Core\Facades\Capabilities;
use Tests\TestCase;

/**
 * Entitlement keys are picked from core's `billing` capabilities, by label, so an admin
 * adds a package without knowing its key. Keys the catalogue doesn't list still work.
 */
class EntitlementCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        Capabilities::add('billing', [
            'packages:acme/reports' => ['label' => 'Package: Reports'],
            'seats' => ['label' => 'Seats', 'type' => 'limit'],
        ]);
    }

    public function test_the_key_is_picked_by_label(): void
    {
        $this->assertSame('Package: Reports', ProductForm::entitlementLabel('packages:acme/reports'));
        $this->assertArrayHasKey('packages:acme/reports', ProductForm::entitlementSearch('report'));
        $this->assertSame('Package: Reports', ProductForm::entitlementSearch('report')['packages:acme/reports']);
    }

    /** What is typed can be used as it is, for a key the catalogue doesn't list. */
    public function test_a_key_the_catalogue_does_not_list_can_still_be_typed(): void
    {
        $this->assertArrayHasKey('max_projects', ProductForm::entitlementSearch('max_projects'));
        $this->assertSame('max_projects', ProductForm::entitlementLabel('max_projects'));
    }

    /** The catalogue says what a key is: picking a feature after a limit must not keep Limit. */
    public function test_picking_a_key_sets_its_type_both_ways(): void
    {
        $plan = Product::factory()->create(['kind' => PlanKind::OneOff]);
        $page = Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->fillForm(['entitlements' => [['key' => 'seats', 'type' => 'feature']]]);
        $row = array_key_first($page->get('data.entitlements'));

        $page->set("data.entitlements.{$row}.key", 'seats')
            ->assertSet("data.entitlements.{$row}.type", 'limit')
            ->set("data.entitlements.{$row}.limit", 30)
            ->set("data.entitlements.{$row}.key", 'packages:acme/reports')
            ->assertSet("data.entitlements.{$row}.type", 'feature')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($plan->fresh()->entitlements()->allows('packages:acme/reports'));
    }

    public function test_a_one_off_plan_can_include_a_package(): void
    {
        $plan = Product::factory()->create(['kind' => PlanKind::OneOff]);

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->fillForm(['entitlements' => [['key' => 'packages:acme/reports', 'type' => 'feature']]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified(__('Not in the catalogue: :keys', ['keys' => 'packages:acme/reports']));

        $this->assertTrue($plan->fresh()->entitlements()->allows('packages:acme/reports'));
    }

    /** Existing keys load and save as they were, and an unknown one warns without blocking. */
    public function test_an_unknown_key_saves_with_a_warning(): void
    {
        $plan = Product::factory()->create(['entitlements' => ['features' => ['legacy_flag' => true], 'limits' => []]]);

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('Not in the catalogue: :keys', ['keys' => 'legacy_flag']));

        $this->assertSame(['features' => ['legacy_flag' => true], 'limits' => []], $plan->fresh()->entitlements);
    }
}
