<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Filament\Resources\Events\RecordSaved;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    /** Typing a catalogue label must not offer a second "Seats" that is only the typed text. */
    public function test_the_typed_option_reads_differently_from_a_catalogue_match(): void
    {
        $options = ProductForm::entitlementSearch('Seats');

        $this->assertSame('Seats', $options['seats']);
        $this->assertSame('Use the key "Seats"', $options['Seats']);
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

    /** The warning says "Saved": a listener rolling the save back must not leave it behind. */
    public function test_a_rolled_back_save_sends_no_warning(): void
    {
        $plan = Product::factory()->create(['entitlements' => ['features' => ['legacy_flag' => true], 'limits' => []]]);
        Event::listen(RecordSaved::class, fn () => throw (new Halt)->rollBackDatabaseTransaction());

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->call('save')
            ->assertNotNotified(__('Not in the catalogue: :keys', ['keys' => 'legacy_flag']));
    }

    /** The sanitiser cuts input at 500,000 bytes: saving another field must not write the cut copy back. */
    public function test_saving_another_field_keeps_a_long_description_whole(): void
    {
        $description = str_repeat('<p>Long description.</p>', 25_000);
        $plan = Product::factory()->create(['kind' => PlanKind::OneOff, 'description' => $description]);
        $this->assertGreaterThan(500_000, strlen($description));

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = (string) DB::table('products')->where('id', $plan->getKey())->value('description');
        $this->assertSame(strlen($description), strlen($stored), 'the stored description was cut');
    }
}
