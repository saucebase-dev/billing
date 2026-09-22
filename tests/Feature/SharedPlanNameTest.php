<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;

/**
 * The plan name every page carries, shown under the user's name in the sidebar.
 * A subscription names the plan first, then a lifetime plan, then the free one.
 */
class SharedPlanNameTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->customer = Customer::factory()->create(['user_id' => $this->user->id]);
    }

    private function assertPlanName(?string $expected): void
    {
        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('billing.plan', $expected));
    }

    private function buyLifetime(string $name = 'Lifetime'): void
    {
        Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->oneTime()->create(['product_id' => Product::factory()->lifetime()->create(['name' => $name])->id])->id,
        ]);
    }

    public function test_no_plan_at_all_names_nothing(): void
    {
        $this->assertPlanName(null);
    }

    public function test_the_free_plan_is_named_when_nothing_is_bought(): void
    {
        Product::factory()->free()->create(['name' => 'Free']);

        $this->assertPlanName('Free');
    }

    public function test_a_lifetime_plan_is_named(): void
    {
        Product::factory()->free()->create(['name' => 'Free']);
        $this->buyLifetime();

        $this->assertPlanName('Lifetime');
    }

    public function test_a_subscription_is_named_first(): void
    {
        $this->buyLifetime();
        Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->create(['product_id' => Product::factory()->create(['name' => 'Team'])->id])->id,
        ]);

        $this->assertPlanName('Team');
    }

    public function test_a_guest_carries_no_plan(): void
    {
        $this->get(route('billing.plans'))->assertInertia(fn (AssertableInertia $page) => $page->where('billing.plan', null));
    }

    /** Two lifetime plans: the latest bought is named, the same on every page. */
    public function test_the_latest_lifetime_plan_is_named(): void
    {
        $this->buyLifetime('Lifetime Pro');
        $this->buyLifetime('Lifetime Team');

        $this->assertPlanName('Lifetime Team');
    }

    /** The plan name and the Upgrade item both ask what the owner holds; the answer is read once. */
    public function test_a_page_reads_the_owners_plans_once(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (preg_match('/from [`"](subscriptions|payments)[`"]/', $query->sql, $table)) {
                $queries[] = $table[1];
            }
        });

        $this->actingAs($this->user)->get(route('dashboard'))->assertOk();

        $this->assertSame(['subscriptions', 'payments'], array_values(array_unique($queries)));
        $this->assertCount(2, $queries);
    }
}
