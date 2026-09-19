<?php

namespace Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Modules\Billing\Services\CatalogSync;

class SyncCatalogCommand extends Command
{
    protected $signature = 'billing:sync-catalog';

    protected $description = 'Pull products and prices from the payment provider into the app';

    public function handle(CatalogSync $sync): int
    {
        $report = $sync->run();

        $this->components->info("{$report->created} created, {$report->updated} updated, {$report->skipped} archived and skipped");

        if ($report->localOnly !== []) {
            $this->components->warn('Not at the provider, left as they are: '.implode(', ', $report->localOnly));
        }

        return self::SUCCESS;
    }
}
