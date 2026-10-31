<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Filament\Resources\Products\Pages\CreateProduct;
use Modules\Billing\Filament\Resources\Products\Pages\EditProduct;
use Modules\Billing\Filament\Resources\Products\ProductResource;
use Modules\Billing\Filament\Resources\Products\Schemas\ProductForm;
use Modules\Billing\Models\Product;
use Saucebase\Core\Capabilities;
use Tests\TestCase;

/**
 * Creating a plan is a wizard (Basics, Plan, Pricing, Display); editing shows
 * the same groups as tabs. The guide opens from the header, not a side panel.
 */
class ProductFormLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    public function test_a_plan_is_created_through_the_wizard(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertWizardCurrentStep(1)
            ->fillForm(['name' => 'Starter'])
            ->goToNextWizardStep()
            ->assertWizardCurrentStep(2)
            ->fillForm([
                'kind' => PlanKind::Subscription->value,
                'entitlements' => [['key' => 'export data', 'type' => 'feature']],
            ])
            ->goToWizardStep(3)
            ->goToWizardStep(4)
            ->assertHasNoFormErrors()
            ->assertWizardCurrentStep(4)
            ->call('create')
            ->assertHasNoFormErrors();

        $plan = Product::where('name', 'Starter')->sole();
        $this->assertTrue($plan->entitlements()->allows('export data'));
    }

    public function test_the_wizard_stops_on_a_missing_name(): void
    {
        Livewire::test(CreateProduct::class)
            ->goToNextWizardStep()
            ->assertHasFormErrors(['name' => 'required'])
            ->assertWizardCurrentStep(1);
    }

    public function test_editing_shows_the_groups_as_tabs(): void
    {
        $plan = Product::factory()->create();

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->assertSchemaComponentExists('product-tabs');
    }

    /** The same icon marks a group as a wizard step and as a tab. */
    public function test_steps_and_tabs_share_their_icons(): void
    {
        $plan = Product::factory()->create();
        $stepIcons = array_map(fn (Step $step) => $step->getIcon(), ProductForm::steps());

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->assertSchemaComponentExists('product-tabs', checkComponentUsing: function (Tabs $tabs) use ($stepIcons): bool {
                $tabIcons = array_map(fn (Tab $tab) => $tab->getIcon(), array_filter($tabs->getChildSchema()->getComponents(), fn ($c) => $c instanceof Tab));
                $this->assertSame($stepIcons, $tabIcons);
                $this->assertNotContains(null, $tabIcons);

                return true;
            });
    }

    /** The view page shows what the plan grants, not only what it costs. */
    public function test_the_view_page_shows_the_plan_and_its_entitlements(): void
    {
        $pro = Product::factory()->create(['name' => 'Pro']);
        $plan = Product::factory()->lifetime($pro)->create([
            'entitlements' => [
                'features' => ['packages:saucebase/tenancy' => true],
                'limits' => ['projects' => null, 'api_requests' => 1000000],
            ],
        ]);

        $this->get(ProductResource::getUrl('view', ['record' => $plan]))
            ->assertOk()
            ->assertSeeInOrder([
                __('Plan'), __('Lifetime'), 'Pro',
                'packages:saucebase/tenancy', __('On'),
                'projects', __('Unlimited'),
                'api_requests', '1,000,000',
                __('Pricing'),
            ]);
    }

    public function test_the_view_page_shows_prices_in_their_currency(): void
    {
        app()->setLocale('en');
        $plan = Product::factory()->create();
        $plan->prices()->create(['amount' => 29900, 'currency' => 'EUR', 'interval' => null, 'provider' => 'stripe']);

        $this->get(ProductResource::getUrl('view', ['record' => $plan]))
            ->assertSee('€299.00 one-time')
            ->assertDontSee('$299.00');
    }

    /** Keys the modules offer are suggested; anything else can still be typed. */
    public function test_entitlement_keys_suggest_the_billing_capabilities(): void
    {
        app(Capabilities::class)->add('billing', fn (): array => ['webhooks' => ['label' => 'Webhooks']]);
        $plan = Product::factory()->create(['entitlements' => ['features' => ['custom key' => true]]]);

        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->assertSeeHtml('value="webhooks"')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($plan->fresh()->entitlements()->allows('custom key'));
    }

    public function test_a_plan_without_entitlements_says_so(): void
    {
        $plan = Product::factory()->create(['entitlements' => null]);

        $this->get(ProductResource::getUrl('view', ['record' => $plan]))
            ->assertOk()
            ->assertSee(__('No entitlements'));
    }

    public function test_the_guide_opens_from_the_header(): void
    {
        $plan = Product::factory()->create();

        Livewire::test(CreateProduct::class)->assertActionExists('guide');
        Livewire::test(EditProduct::class, ['record' => $plan->getKey()])
            ->assertActionExists('guide')
            ->mountAction('guide')
            ->assertMountedActionModalSee([
                'Kind, slug and replaced plan are fixed once the plan is sold',
                'Leave Unlimited on',
                'Amounts are in cents',
                'original_price',
                'cta_url',
            ]);
    }
}
