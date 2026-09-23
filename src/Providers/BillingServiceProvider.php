<?php

namespace Modules\Billing\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Modules\Billing\Contracts\BillingOwner;
use Modules\Billing\Contracts\PaymentGatewayInterface;
use Modules\Billing\Services\BillingService;
use Modules\Billing\Services\PaymentGatewayManager;
use Saucebase\Core\Providers\ModuleServiceProvider;

class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->singleton(PaymentGatewayManager::class);

        $this->app->bind(PaymentGatewayInterface::class, function ($app) {
            return $app->make(PaymentGatewayManager::class)->driver();
        });

        $this->app->singleton(BillingService::class);
    }

    public function boot(): void
    {
        parent::boot();

        $this->loadViewsFrom(module_path('billing', 'resources/views'), 'billing');

        Inertia::share('billing.plan', fn () => Auth::user() instanceof BillingOwner ? Auth::user()->planName() : null);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('billing:expire-checkout-sessions')->everyThirtyMinutes();
            $schedule->command('billing:sync-catalog')->daily();
            $schedule->command('billing:end-grace-periods')->hourly();
        });
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        parent::registerConfig();

        $this->mergeConfigFrom(module_path('billing', 'config/services.php'), 'services');
    }
}
