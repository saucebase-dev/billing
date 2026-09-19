<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Enums\BillingScheme;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Models\Product;

/**
 * The demo site's plans.
 *
 * They carry no provider IDs: nothing here exists at a payment provider until
 * somebody pushes it there (`billing:push-catalog`), and claiming an ID we do
 * not own would make the push skip them and checkout fail against a price
 * Stripe has never heard of.
 *
 * A real app defines its own products, which is why these are demo content
 * rather than install data.
 */
class DemoProductSeeder extends Seeder
{
    public function run(): void
    {

        $this->createFreeProduct();
        $this->createProProduct();
        $this->createTeamProduct();
    }

    private function createFreeProduct(): void
    {
        $product = Product::updateOrCreate(
            ['slug' => 'free'],
            [
                'sku' => 'free',
                'name' => 'Free',
                'description' => 'Get started with the basics',
                'display_order' => 1,
                'is_visible' => true,
                'is_highlighted' => false,
                'is_active' => true,
                'features' => [
                    '1 project',
                    '500MB storage',
                    'Community support',
                ],
                'metadata' => [
                    'tagline' => 'For hobbyists',
                ],
            ]
        );

        foreach ([
            [
                'currency' => Currency::default(),
                'amount' => 0,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => 'month',
                'interval_count' => 1,
                'is_active' => true,
            ],
            [
                'currency' => Currency::default(),
                'amount' => 0,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => 'year',
                'interval_count' => 1,
                'is_active' => true,
            ],
        ] as $price) {
            $product->prices()->updateOrCreate(
                ['interval' => $price['interval'], 'amount' => $price['amount']],
                $price
            );
        }
    }

    private function createProProduct(): void
    {
        $product = Product::updateOrCreate(
            ['slug' => 'pro'],
            [
                'sku' => 'pro',
                'name' => 'Pro',
                'description' => 'Everything you need to work independently',
                'display_order' => 3,
                'is_visible' => true,
                'is_highlighted' => true,
                'is_active' => true,
                'features' => [
                    'Unlimited projects',
                    '50GB storage',
                    'Priority email support',
                    'Advanced analytics',
                    'API access',
                    'Custom domains',
                ],
                'metadata' => [
                    'badge' => 'Most Popular',
                    'tagline' => 'For professionals',
                ],
            ]
        );

        foreach ([
            [
                'currency' => Currency::default(),
                'amount' => 2900,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => 'month',
                'interval_count' => 1,
                'is_active' => true,
            ],
            [
                'currency' => Currency::default(),
                'amount' => 29000,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => 'year',
                'interval_count' => 1,
                'is_active' => true,
                'metadata' => [
                    'badge' => 'Save 17%',
                    'label' => 'Billed annually',
                    'original_price' => '34800',
                ],
            ],
            [
                'currency' => Currency::default(),
                'amount' => 29900,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => null,
                'interval_count' => null,
                'is_active' => false,
            ],
        ] as $price) {
            $product->prices()->updateOrCreate(
                ['interval' => $price['interval'], 'amount' => $price['amount']],
                $price
            );
        }
    }

    private function createTeamProduct(): void
    {
        $product = Product::updateOrCreate(
            ['slug' => 'team'],
            [
                'sku' => 'team',
                'name' => 'Team',
                'description' => 'Collaborate with your team, up to 25 members',
                'display_order' => 4,
                'is_visible' => true,
                'is_highlighted' => false,
                'is_active' => true,
                'features' => [
                    'Everything in Pro',
                    'Up to 25 team members',
                    '200GB shared storage',
                    'Team roles & permissions',
                    'Priority support',
                    'Shared dashboards',
                    'Audit logs',
                ],
                'metadata' => [
                    'tagline' => 'For teams',
                ],
            ]
        );

        foreach ([
            [
                'currency' => Currency::default(),
                'amount' => 7900,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => 'month',
                'interval_count' => 1,
                'is_active' => true,
            ],
            [
                'currency' => Currency::default(),
                'amount' => 79000,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => 'year',
                'interval_count' => 1,
                'is_active' => true,
                'metadata' => [
                    'badge' => 'Save 17%',
                    'label' => 'Billed annually',
                    'original_price' => '94800',
                ],
            ],
            [
                'currency' => Currency::default(),
                'amount' => 79900,
                'billing_scheme' => BillingScheme::FlatRate,
                'interval' => null,
                'interval_count' => null,
                'is_active' => false,
            ],
        ] as $price) {
            $product->prices()->updateOrCreate(
                ['interval' => $price['interval'], 'amount' => $price['amount']],
                $price
            );
        }
    }
}
