<?php

namespace Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Modules\Billing\Enums\CheckoutSessionStatus;
use Modules\Billing\Models\CheckoutSession;
use Modules\Billing\Settings\BillingSettings;

class ExpireCheckoutSessionsCommand extends Command
{
    protected $signature = 'billing:expire-checkout-sessions';

    protected $description = 'Mark expired pending checkout sessions as expired';

    public function handle(): int
    {
        $expired = CheckoutSession::where('status', CheckoutSessionStatus::Pending)
            ->where('expires_at', '<', now())
            ->update(['status' => CheckoutSessionStatus::Expired]);

        $abandonedBefore = now()->subMinutes(app(BillingSettings::class)->checkout_abandon_after_minutes);

        $abandoned = CheckoutSession::where('status', CheckoutSessionStatus::Pending)
            ->where('created_at', '<', $abandonedBefore)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->update(['status' => CheckoutSessionStatus::Abandoned]);

        $this->info("Marked {$expired} session(s) as expired, {$abandoned} as abandoned.");

        return self::SUCCESS;
    }
}
