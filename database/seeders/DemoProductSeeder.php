<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Enums\BillingScheme;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Models\Product;

/**
 * The demo site's plans, for a made-up developer tool. Between them they use
 * every option the pricing page reads: a free plan, monthly and yearly prices
 * with a discount, a highlighted plan, a one-time price, a plan with no price
 * that links to sales, and per-plan button labels and small print.
 *
 * They carry no provider IDs: claiming an ID we do not own would make the push
 * skip them and checkout fail against a price Stripe has never heard of.
 * `DemoBillingDatabaseSeeder` pushes them once every seeder has run.
 *
 * A real app defines its own products, which is why these are demo content
 * rather than install data.
 */
class DemoProductSeeder extends Seeder
{
    /** The plans this seeder owns, and the only ones the demo may push. */
    public const SLUGS = ['free', 'pro', 'team', 'lifetime', 'enterprise'];

    public function run(): void
    {
        foreach ($this->plans() as $order => $plan) {
            $product = Product::updateOrCreate(
                ['slug' => $plan['slug']],
                [
                    'sku' => $plan['slug'],
                    'name' => $plan['name'],
                    'description' => $plan['description'],
                    'display_order' => $order + 1,
                    'is_visible' => true,
                    'is_highlighted' => $plan['slug'] === 'pro',
                    'is_active' => true,
                    'features' => $plan['features'],
                    'metadata' => $plan['metadata'],
                ],
            );

            foreach ($plan['prices'] as $price) {
                $product->prices()->updateOrCreate(
                    ['interval' => $price['interval'], 'amount' => $price['amount']],
                    [
                        'currency' => Currency::default(),
                        'billing_scheme' => BillingScheme::FlatRate,
                        'interval_count' => $price['interval'] === null ? null : 1,
                        'is_active' => true,
                        'metadata' => $price['metadata'] ?? null,
                    ] + $price,
                );
            }
        }
    }

    /**
     * @return list<array{slug: string, name: string, description: string, features: list<string>, metadata: array<string, string>, prices: list<array{interval: ?string, amount: int, metadata?: array<string, string>}>}>
     */
    private function plans(): array
    {
        return [
            [
                'slug' => 'free',
                'name' => 'Free',
                'description' => 'Everything you need to ship a side project.',
                'features' => ['3 projects', '10k API requests / month', 'Community support'],
                'metadata' => [
                    'tagline' => 'For side projects',
                    'cta_label' => 'Start for free',
                    'after_cta' => 'No card required',
                ],
                'prices' => [
                    ['interval' => 'month', 'amount' => 0],
                    ['interval' => 'year', 'amount' => 0],
                ],
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'No limits on projects, and the tools to run them in production.',
                'features' => ['Unlimited projects', '1M API requests / month', 'Custom domains', 'Webhooks', 'Priority email support'],
                'metadata' => [
                    'tagline' => 'For solo developers',
                    'after_cta' => 'Cancel anytime',
                ],
                'prices' => [
                    ['interval' => 'month', 'amount' => 2900],
                    ['interval' => 'year', 'amount' => 29000, 'metadata' => ['badge' => 'Save 17%', 'original_price' => '34800']],
                ],
            ],
            [
                'slug' => 'team',
                'name' => 'Team',
                'description' => 'Work together on every project, up to 25 members.',
                'features' => ['Everything in Pro', 'Up to 25 members', 'Roles & permissions', 'Audit logs', 'SSO (SAML)'],
                'metadata' => [
                    'tagline' => 'For growing teams',
                    'after_cta' => 'Cancel anytime',
                ],
                'prices' => [
                    ['interval' => 'month', 'amount' => 7900],
                    ['interval' => 'year', 'amount' => 79000, 'metadata' => ['badge' => 'Save 17%', 'original_price' => '94800']],
                ],
            ],
            [
                'slug' => 'lifetime',
                'name' => 'Lifetime',
                'description' => 'Pro, paid once and never again.',
                'features' => ['Everything in Pro, forever', 'All future updates'],
                'metadata' => [
                    'tagline' => 'Pay once, own it',
                    'cta_label' => 'Buy lifetime',
                    'after_cta' => 'One payment, yours forever',
                ],
                'prices' => [
                    ['interval' => null, 'amount' => 29900],
                ],
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Custom limits, contracts and support for large organisations.',
                'features' => ['Unlimited members', 'Uptime SLA', 'Dedicated support engineer', 'Self-hosted option'],
                'metadata' => [
                    'tagline' => 'For large organisations',
                    'cta_label' => 'Contact sales',
                    'cta_url' => 'mailto:sales@example.com',
                ],
                'prices' => [],
            ],
        ];
    }
}
