<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** PostgreSQL and SQLite do not index a foreign key on their own; MySQL does. */
class ForeignKeyIndexTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function foreignKeys(): array
    {
        return [
            'prices.product_id' => ['prices', 'product_id'],
            'products.replaces_product_id' => ['products', 'replaces_product_id'],
            'payment_methods.customer_id' => ['payment_methods', 'customer_id'],
            'checkout_sessions.customer_id' => ['checkout_sessions', 'customer_id'],
            'checkout_sessions.price_id' => ['checkout_sessions', 'price_id'],
            'subscriptions.customer_id' => ['subscriptions', 'customer_id'],
            'subscriptions.price_id' => ['subscriptions', 'price_id'],
            'subscriptions.payment_method_id' => ['subscriptions', 'payment_method_id'],
            'payments.customer_id' => ['payments', 'customer_id'],
            'payments.subscription_id' => ['payments', 'subscription_id'],
            'payments.payment_method_id' => ['payments', 'payment_method_id'],
            'payments.price_id' => ['payments', 'price_id'],
            'invoices.customer_id' => ['invoices', 'customer_id'],
            'invoices.subscription_id' => ['invoices', 'subscription_id'],
            'invoices.payment_id' => ['invoices', 'payment_id'],
        ];
    }

    #[DataProvider('foreignKeys')]
    public function test_every_foreign_key_is_indexed(string $table, string $column): void
    {
        $this->assertTrue(Schema::hasIndex($table, [$column]), "{$table}.{$column} has no index of its own.");
    }
}
