<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Seeders\DatabaseSeeder;
use Modules\Billing\Database\Seeders\ShowcaseBillingDatabaseSeeder;
use Modules\Billing\Database\Seeders\ShowcaseProductSeeder;
use Modules\Billing\Enums\PlanKind;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Promotion;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;

class ShowcaseSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The demo dashboard is only worth showing if every widget has something to
     * read: subscriptions for MRR, payments for revenue, and a mix of statuses.
     */
    public function test_the_demo_seeder_fills_every_dashboard_widget(): void
    {
        $this->seed([DatabaseSeeder::class, ShowcaseBillingDatabaseSeeder::class]);

        $this->assertGreaterThan(0, Customer::count());
        $this->assertGreaterThan(0, Payment::count());
        $this->assertGreaterThan(0, Invoice::count());
        $this->assertGreaterThan(0, Subscription::where('status', SubscriptionStatus::Active)->count());
        $this->assertGreaterThan(0, Subscription::where('status', SubscriptionStatus::Cancelled)->count());
    }

    /** An app has one free plan: a template's own stays, and the showcase adds the rest. */
    public function test_the_showcase_keeps_a_free_plan_the_app_already_has(): void
    {
        $ownFree = Product::factory()->create(['slug' => 'uptime-free', 'kind' => PlanKind::Free]);

        $this->seed([DatabaseSeeder::class, ShowcaseBillingDatabaseSeeder::class]);

        $this->assertSame([$ownFree->id], Product::where('kind', PlanKind::Free)->pluck('id')->all());
        $this->assertTrue(Product::where('slug', 'pro')->exists());
    }

    /** Re-running the demo seeder must not double the data. */
    public function test_seeding_twice_leaves_the_same_data(): void
    {
        $this->seed([DatabaseSeeder::class, ShowcaseBillingDatabaseSeeder::class]);
        $first = [Customer::count(), Subscription::count(), Payment::count()];

        $this->seed([DatabaseSeeder::class, ShowcaseBillingDatabaseSeeder::class]);

        $this->assertSame($first, [Customer::count(), Subscription::count(), Payment::count()]);
    }

    /** The demo pricing page shows a launch price: Lifetime, first 50 buyers. */
    public function test_lifetime_carries_a_launch_promotion(): void
    {
        $this->seed([DatabaseSeeder::class, ShowcaseBillingDatabaseSeeder::class]);
        $this->seed([DatabaseSeeder::class, ShowcaseBillingDatabaseSeeder::class]);

        $promotion = Promotion::sole();
        $lifetime = Product::where('slug', 'lifetime')->sole()->prices()->sole();

        $this->assertSame('LAUNCH', $promotion->code);
        $this->assertSame($promotion->id, $lifetime->promotion_id);
        $this->assertSame(19900, $promotion->discountedAmount($lifetime));
        $this->assertSame(50, $promotion->max_redemptions);
        // Shown once the push has made it real at the provider; tests never push.
        $this->assertNull($promotion->provider_promotion_code_id);
        $this->assertFalse(Promotion::available()->whereKey($promotion->id)->exists());
    }

    /** Pro's trial shows the feature: fewer API requests, no custom domains. */
    public function test_the_pro_trial_is_narrower_than_pro(): void
    {
        $this->seed(ShowcaseProductSeeder::class);

        $trial = Product::where('slug', 'pro')->sole()->entitlements(trial: true);

        $this->assertFalse($trial->allows('custom_domains'));
        $this->assertTrue($trial->allows('webhooks'));
        $this->assertSame(10000, $trial->limit('api_requests'));
    }
}
