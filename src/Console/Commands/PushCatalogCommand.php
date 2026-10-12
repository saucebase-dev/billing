<?php

namespace Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Modules\Billing\Services\CatalogPush;
use Modules\Billing\Services\PaymentGatewayManager;

class PushCatalogCommand extends Command
{
    protected $signature = 'billing:push-catalog';

    protected $description = 'Create products and prices that exist only here at the payment provider';

    public function handle(CatalogPush $push, PaymentGatewayManager $manager): int
    {
        $report = $push->run();

        $this->components->info("{$report->products} products, {$report->prices} prices created and {$report->features} feature lists sent to {$manager->getDefaultDriver()}");

        return self::SUCCESS;
    }
}
