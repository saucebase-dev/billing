<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Filament\Resources\Products\Pages\CreateProduct;
use Modules\Billing\Filament\Resources\Products\Pages\EditProduct;
use Modules\Billing\Filament\Resources\Products\Pages\ViewProduct;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The admin is told what each kind of plan means, on the form and on the view page. */
class PlanKindExplainedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    /** @return array<string, array{PlanKind}> */
    public static function kinds(): array
    {
        return array_combine(
            array_map(fn (PlanKind $kind) => $kind->value, PlanKind::cases()),
            array_map(fn (PlanKind $kind) => [$kind], PlanKind::cases()),
        );
    }

    #[DataProvider('kinds')]
    public function test_the_form_explains_the_selected_kind(PlanKind $kind): void
    {
        $page = Livewire::test(CreateProduct::class)->set('data.kind', $kind);

        $page->assertSee($kind->description());
        foreach (PlanKind::cases() as $other) {
            if ($other !== $kind) {
                $page->assertDontSee($other->description());
            }
        }
    }

    public function test_a_sold_plan_says_it_is_fixed_and_still_explains_its_kind(): void
    {
        $plan = Product::factory()->create(['kind' => PlanKind::Subscription]);
        Subscription::factory()->create(['price_id' => Price::factory()->for($plan)->create()->id]);

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->assertSee('Fixed: this plan has been sold.')
            ->assertSee(PlanKind::Subscription->description());
    }

    #[DataProvider('kinds')]
    public function test_the_view_page_explains_the_kind(PlanKind $kind): void
    {
        $plan = Product::factory()->create(['kind' => $kind]);

        Livewire::test(ViewProduct::class, ['record' => $plan->getKey()])
            ->assertSee($kind->description());
    }

    /** What it is, gives and costs comes before the plan's IDs. */
    public function test_the_view_page_puts_kind_entitlements_and_prices_before_the_details(): void
    {
        $plan = Product::factory()->create([
            'kind' => PlanKind::Subscription,
            'sku' => 'SKU-ORDER-CHECK',
            'entitlements' => ['features' => ['exports' => true], 'limits' => []],
        ]);
        Price::factory()->for($plan)->create();

        Livewire::test(ViewProduct::class, ['record' => $plan->getKey()])
            ->assertSeeInOrder([PlanKind::Subscription->description(), 'exports: On', 'Price', 'SKU-ORDER-CHECK']);
    }

    public function test_a_one_off_plan_without_entitlements_shows_no_empty_line(): void
    {
        $plan = Product::factory()->create(['kind' => PlanKind::OneOff, 'entitlements' => null]);

        Livewire::test(ViewProduct::class, ['record' => $plan->getKey()])
            ->assertDontSee('No entitlements');
    }

    public function test_a_one_off_plan_with_entitlements_lists_them(): void
    {
        $plan = Product::factory()->create([
            'kind' => PlanKind::OneOff,
            'entitlements' => ['features' => ['packages:acme/widgets' => true], 'limits' => []],
        ]);

        Livewire::test(ViewProduct::class, ['record' => $plan->getKey()])
            ->assertSee('packages:acme/widgets: On');
    }
}
