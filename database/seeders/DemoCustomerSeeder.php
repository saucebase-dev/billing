<?php

namespace Modules\Billing\Database\Seeders;

use App\Models\User;
use Carbon\CarbonInterface;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Billing\Enums\PaymentMethodType;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\PaymentMethod;

/**
 * The people behind the demo dashboard: a customer and a card for each.
 *
 * Signup dates run back over the last year on a rising curve, which is what gives
 * the revenue and subscription charts a shape worth looking at. They are laid out
 * here, in one place, because DemoSubscriptionSeeder bills from them.
 */
class DemoCustomerSeeder extends Seeder
{
    /**
     * How many customers signed up each month, oldest month first. Twelve entries,
     * one per month back from this one.
     */
    public const SIGNUPS_PER_MONTH = [1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7];

    private const CARD_BRANDS = ['visa', 'mastercard', 'amex'];

    public function run(): void
    {
        // Its own generator, not the shared fake(): seeding that one would make
        // every other seeder in the same run deterministic too.
        $faker = Factory::create();
        $faker->seed(20260918);

        // Hashed once: bcrypt per user is what made this seeder slow, and every
        // demo account shares the same public password anyway.
        $password = Hash::make('password');

        $index = 0;

        foreach (self::SIGNUPS_PER_MONTH as $monthsBack => $signups) {
            for ($i = 0; $i < $signups; $i++) {
                // Spread the month's signups across its days so the daily charts
                // are not a single spike per month.
                $signedUpAt = now()
                    ->subMonths(count(self::SIGNUPS_PER_MONTH) - 1 - $monthsBack)
                    ->startOfMonth()
                    ->addDays(($i * 5 + 2) % 27)
                    ->addHours(9 + $i);

                $this->createCustomer($index, $faker->name(), $signedUpAt, $faker, $password);

                $index++;
            }
        }
    }

    private function createCustomer(int $index, string $name, CarbonInterface $signedUpAt, Generator $faker, string $password): void
    {
        $reference = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        $email = "demo-customer-{$reference}@example.com";

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'email_verified_at' => $signedUpAt,
                'created_at' => $signedUpAt,
                'updated_at' => $signedUpAt,
            ],
        );

        $user->assignRole('user');

        $customer = $user->billingCustomer()->firstOrCreate(
            [],
            [
                'provider' => 'stripe',
                'email' => $email,
                'name' => $name,
                'phone' => $faker->phoneNumber(),
                'address' => [
                    'line1' => $faker->streetAddress(),
                    'city' => $faker->city(),
                    'postal_code' => $faker->postcode(),
                    'country' => $faker->countryCode(),
                ],
                'created_at' => $signedUpAt,
                'updated_at' => $signedUpAt,
            ],
        );

        PaymentMethod::firstOrCreate(
            ['provider' => 'stripe',
                'provider_payment_method_id' => "pm_demo_{$reference}"],
            [
                'customer_id' => $customer->id,
                'type' => PaymentMethodType::Card,
                'details' => [
                    'brand' => self::CARD_BRANDS[$index % count(self::CARD_BRANDS)],
                    'last4' => str_pad((string) (($index * 37) % 10000), 4, '0', STR_PAD_LEFT),
                    'exp_month' => ($index % 12) + 1,
                    'exp_year' => now()->addYears(2)->year,
                ],
                'is_default' => true,
                'created_at' => $signedUpAt,
                'updated_at' => $signedUpAt,
            ],
        );
    }
}
