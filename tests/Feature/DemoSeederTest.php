<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Seeders\DatabaseSeeder;
use Modules\Billing\Database\Seeders\DemoBillingDatabaseSeeder;
use Modules\Billing\Database\Seeders\DemoProductSeeder;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The demo dashboard is only worth showing if every widget has something to
     * read: subscriptions for MRR, payments for revenue, and a mix of statuses.
     */
    public function test_the_demo_seeder_fills_every_dashboard_widget(): void
    {
        $this->seed([DatabaseSeeder::class, DemoBillingDatabaseSeeder::class]);

        $this->assertGreaterThan(0, Customer::count());
        $this->assertGreaterThan(0, Payment::count());
        $this->assertGreaterThan(0, Invoice::count());
        $this->assertGreaterThan(0, Subscription::where('status', SubscriptionStatus::Active)->count());
        $this->assertGreaterThan(0, Subscription::where('status', SubscriptionStatus::Cancelled)->count());
    }

    /** Re-running the demo seeder must not double the data. */
    public function test_seeding_twice_leaves_the_same_data(): void
    {
        $this->seed([DatabaseSeeder::class, DemoBillingDatabaseSeeder::class]);
        $first = [Customer::count(), Subscription::count(), Payment::count()];

        $this->seed([DatabaseSeeder::class, DemoBillingDatabaseSeeder::class]);

        $this->assertSame($first, [Customer::count(), Subscription::count(), Payment::count()]);
    }

    /** Pro's trial shows the feature: fewer API requests, no custom domains. */
    public function test_the_pro_trial_is_narrower_than_pro(): void
    {
        $this->seed(DemoProductSeeder::class);

        $trial = Product::where('slug', 'pro')->sole()->entitlements(trial: true);

        $this->assertFalse($trial->allows('custom_domains'));
        $this->assertTrue($trial->allows('webhooks'));
        $this->assertSame(10000, $trial->limit('api_requests'));
    }
}
