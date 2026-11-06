<?php

namespace Modules\Billing\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Modules\Billing\Filament\Widgets\BillingHealthWidget;
use Modules\Billing\Filament\Widgets\BillingSaasStatsWidget;
use Modules\Billing\Filament\Widgets\ConversionChartWidget;
use Modules\Billing\Filament\Widgets\RevenueByPlanChartWidget;
use Modules\Billing\Filament\Widgets\RevenueChartWidget;
use Modules\Billing\Filament\Widgets\SubscriptionsChartWidget;
use Saucebase\Core\Filament\Pages\Dashboard;

/** Core's dashboard, period picker included, with billing's own widgets. */
class BillingDashboard extends Dashboard
{
    protected static string $routePath = 'billing-dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?int $navigationSort = 200;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage billing') ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dashboard');
    }

    public function getTitle(): string
    {
        return __('Billing');
    }

    public function getWidgets(): array
    {
        return [
            BillingSaasStatsWidget::class,
            BillingHealthWidget::class,
            RevenueChartWidget::class,
            SubscriptionsChartWidget::class,
            ConversionChartWidget::class,
            RevenueByPlanChartWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
