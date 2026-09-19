<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Seeders\DemoBillingDatabaseSeeder;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
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
        $this->seed(DemoBillingDatabaseSeeder::class);

        $this->assertGreaterThan(0, Customer::count());
        $this->assertGreaterThan(0, Payment::count());
        $this->assertGreaterThan(0, Invoice::count());
        $this->assertGreaterThan(0, Subscription::where('status', SubscriptionStatus::Active)->count());
        $this->assertGreaterThan(0, Subscription::where('status', SubscriptionStatus::Cancelled)->count());
    }

    /** Re-running the demo seeder must not double the data. */
    public function test_seeding_twice_leaves_the_same_data(): void
    {
        $this->seed(DemoBillingDatabaseSeeder::class);
        $first = [Customer::count(), Subscription::count(), Payment::count()];

        $this->seed(DemoBillingDatabaseSeeder::class);

        $this->assertSame($first, [Customer::count(), Subscription::count(), Payment::count()]);
    }
}
