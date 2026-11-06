<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The dashboard filters these columns by date range; without an index each card scans the table. */
class DashboardIndexesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, list<string>}> */
    public static function indexes(): array
    {
        return [
            'payments by status, currency and date' => ['payments', ['status', 'currency', 'created_at']],
            'subscriptions by date' => ['subscriptions', ['created_at']],
            'subscriptions by cancellation' => ['subscriptions', ['cancelled_at']],
            'checkouts by status and date' => ['checkout_sessions', ['status', 'created_at']],
        ];
    }

    /** @param  list<string>  $columns */
    #[DataProvider('indexes')]
    public function test_the_dashboard_queries_are_indexed(string $table, array $columns): void
    {
        $this->assertContains($columns, array_column(Schema::getIndexes($table), 'columns'));
    }
}
