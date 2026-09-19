<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Sample billing content for the demo site, run by `modules:seed --demo`.
 *
 * None of it is install data: the plans carry the demo Stripe account's price IDs,
 * and a real app defines its own.
 */
class DemoBillingDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DemoProductSeeder::class,
            DemoCustomerSeeder::class,
            DemoSubscriptionSeeder::class,
        ]);
    }
}
