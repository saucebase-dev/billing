<?php

namespace Modules\Billing\Filament\Traits;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Month-by-month charts over `$startDate`..`$endDate`. The month is worked out
 * in SQL, which every engine spells differently.
 */
trait GroupsByMonth
{
    /** SQL giving `created_at` as `YYYY-MM`, for the query's own database. */
    protected function monthOf(Builder $query): string
    {
        return match ($query->getModel()->getConnection()->getDriverName()) {
            'pgsql' => "to_char(created_at, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', created_at)",
            default => "date_format(created_at, '%Y-%m')",
        };
    }

    /**
     * Every month in the range, so a month with nothing in it still shows.
     *
     * @return array<string, int|float>
     */
    protected function monthlyBuckets(int|float $empty = 0): array
    {
        $buckets = [];
        $cursor = Carbon::parse($this->startDate)->startOfMonth();
        $end = Carbon::parse($this->endDate)->endOfMonth();

        while ($cursor <= $end) {
            $buckets[$cursor->format('Y-m')] = $empty;
            $cursor->addMonth();
        }

        return $buckets;
    }
}
