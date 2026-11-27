<?php

namespace Modules\Billing\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Billing\Models\Product;
use Modules\Billing\Services\CatalogPush;
use Modules\Billing\Services\PaymentGatewayManager;
use Saucebase\Core\Attributes\DemoAccount;
use Spatie\Permission\Models\Role;

/**
 * Sample billing content for the demo site, run by `modules:seed --state=showcase`.
 *
 * None of it is install data: a real app defines its own plans. The plans are
 * created locally and pushed to the provider when its keys are present, which
 * is what makes the demo site's checkout work.
 */
#[DemoAccount('billing@saucebase.dev', 'staff: the billing admin')]
class ShowcaseBillingDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // A staff account that sees only this module's admin area.
        Role::findOrCreate('billing admin')->syncPermissions(['access admin panel', 'manage billing']);
        $staff = User::firstOrCreate(
            ['email' => 'billing@saucebase.dev'],
            ['name' => 'Billing Admin', 'password' => bcrypt(config('saucebase.demo_password'))],
        );
        $staff->syncRoles('billing admin');

        $this->call([
            ShowcaseProductSeeder::class,
            ShowcaseCustomerSeeder::class,
            ShowcaseSubscriptionSeeder::class,
        ]);

        $this->pushToProvider();
    }

    /**
     * Create the seeded plans at the provider so they can be bought.
     *
     * Runs once the seeders have, rather than inside one of them: it is the
     * only step here that leaves the database, and a seeder's output is
     * swallowed by the task line the runner draws around it.
     *
     * Pushed one demo plan at a time rather than as a whole catalogue: an
     * unscoped run would publish every local draft in the database and resend
     * the marketing copy of plans this seeder knows nothing about.
     *
     * A demo site is reseeded often and the push only sends rows with no
     * provider ID, so a second run adds nothing at the provider.
     */
    private function pushToProvider(): void
    {
        $gateways = app(PaymentGatewayManager::class);

        // A test run must never reach the provider, and a developer's real keys
        // are in the same `.env` the suite boots with.
        if (app()->runningUnitTests() || ! $gateways->isConfigured($gateways->getDefaultDriver())) {
            $this->command->outputComponents()->warn('Billing: no provider keys, demo plans stay local and cannot be bought.');

            return;
        }

        // The plans are already written, so an unreachable provider is not a
        // failed seed: `billing:push-catalog` finishes the job later.
        $push = app(CatalogPush::class);
        $products = 0;
        $prices = 0;
        $promotions = 0;
        $warnings = [];

        try {
            foreach (Product::whereIn('slug', ShowcaseProductSeeder::SLUGS)->get() as $product) {
                $report = $push->run($product);
                $products += $report->products;
                $prices += $report->prices;
                $promotions += $report->promotions;
                array_push($warnings, ...$report->promotionWarnings);
            }
        } catch (\Throwable $e) {
            $this->command->outputComponents()->warn("Billing: push failed ({$e->getMessage()}); run billing:push-catalog when the provider is reachable.");

            return;
        }

        $this->command->outputComponents()->info("Billing: pushed {$products} products, {$prices} prices and {$promotions} promotions to the provider.");

        foreach ($warnings as $warning) {
            $this->command->outputComponents()->warn("Billing: {$warning}");
        }
    }
}
