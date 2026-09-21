<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;

class BillingPlansPageTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $name, array $attributes = []): Product
    {
        $product = Product::factory()->create([
            'name' => $name,
            'is_active' => true,
            'is_visible' => true,
            ...$attributes,
        ]);

        Price::factory()->create(['product_id' => $product->id, 'is_active' => true]);

        return $product;
    }

    /** The pricing page is the module's shop window; only finished plans belong in it. */
    public function test_only_active_and_visible_plans_are_offered(): void
    {
        $this->plan('Shown');
        $this->plan('Imported but unreviewed', ['is_visible' => false]);
        $this->plan('Retired', ['is_active' => false]);
        $this->plan('Deleted here')->delete();

        $this->get(route('billing.plans'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('Billing::Plans', false)
                    ->has('products', 1)
                    ->where('products.0.name', 'Shown')
            );
    }

    /** The admin's drag order is what the page shows, without the controller asking. */
    public function test_plans_come_back_in_the_admin_order(): void
    {
        $this->plan('Third', ['display_order' => 3]);
        $this->plan('First', ['display_order' => 1]);
        $this->plan('Second', ['display_order' => 2]);

        $this->get(route('billing.plans'))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('products.0.name', 'First')
                    ->where('products.1.name', 'Second')
                    ->where('products.2.name', 'Third')
            );
    }

    /** Imports all arrive on the same rank, so the order still has to be decided. */
    public function test_plans_sharing_a_rank_keep_a_stable_order(): void
    {
        $first = $this->plan('A', ['display_order' => 0]);
        $second = $this->plan('B', ['display_order' => 0]);

        $names = fn () => collect($this->get(route('billing.plans'))
            ->viewData('page')['props']['products'])->pluck('name')->all();

        $this->assertSame(['A', 'B'], $names());
        $this->assertLessThan($second->id, $first->id);
    }

    /** A plan nobody can buy would render a broken card. */
    public function test_a_plan_whose_prices_are_all_retired_carries_no_prices(): void
    {
        $product = $this->plan('Legacy');
        $product->prices()->update(['is_active' => false]);

        $this->get(route('billing.plans'))
            ->assertInertia(
                fn (AssertableInertia $page) => $page->has('products.0.prices', 0)
            );
    }

    /** Shown with its button off, rather than leaving an empty card before the push. */
    public function test_a_price_the_provider_does_not_know_yet_is_still_listed(): void
    {
        $product = $this->plan('Drafted');
        $product->prices()->update(['provider_price_id' => null]);

        $this->get(route('billing.plans'))
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('products.0.prices', 1)
                    ->where('products.0.prices.0.provider_price_id', null)
            );
    }

    public function test_a_subscriber_sees_which_plan_is_theirs(): void
    {
        $product = $this->plan('Mine');
        $user = User::factory()->create();
        Subscription::factory()->create([
            'customer_id' => Customer::factory()->create(['user_id' => $user->id])->id,
            'price_id' => $product->prices()->first()->id,
        ]);

        $this->actingAs($user)->get(route('billing.plans'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currentProductId', $product->id));
    }

    public function test_a_cancelled_plan_is_not_current(): void
    {
        $product = $this->plan('Gone');
        $user = User::factory()->create();
        Subscription::factory()->create([
            'customer_id' => Customer::factory()->create(['user_id' => $user->id])->id,
            'price_id' => $product->prices()->first()->id,
            'status' => SubscriptionStatus::Cancelled,
        ]);

        $this->actingAs($user)->get(route('billing.plans'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('currentProductId', null));
    }
}
