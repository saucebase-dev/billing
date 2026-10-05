<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Seeders\DatabaseSeeder;
use Modules\Billing\Filament\Pages\BillingDashboard;
use Modules\Billing\Filament\Pages\BillingSettings;
use Modules\Billing\Filament\Resources\Customers\CustomerResource;
use Modules\Billing\Filament\Resources\Products\ProductResource;
use Modules\Billing\Filament\Resources\Subscriptions\SubscriptionResource;
use Modules\Billing\Filament\Widgets\BillingSaasStatsWidget;
use Modules\Billing\Filament\Widgets\ConversionChartWidget;
use Modules\Billing\Filament\Widgets\RevenueChartWidget;
use Modules\Billing\Filament\Widgets\SubscriptionsChartWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The billing admin area, its dashboard and settings included, is its own permission, so
 * a role can be given billing and nothing else in the panel.
 */
class BillingPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function pages(): array
    {
        return [
            'customers' => [CustomerResource::class],
            'products' => [ProductResource::class],
            'subscriptions' => [SubscriptionResource::class],
            'dashboard' => [BillingDashboard::class],
            'settings' => [BillingSettings::class],
        ];
    }

    public function test_the_seeder_creates_the_permission(): void
    {
        $this->assertTrue(Permission::where('name', 'manage billing')->exists());
    }

    #[DataProvider('pages')]
    public function test_a_billing_admin_can_open_billing(string $page): void
    {
        $this->actingAs($this->staff('access admin panel', 'manage billing'))
            ->get($this->urlOf($page))
            ->assertOk();
    }

    #[DataProvider('pages')]
    public function test_panel_access_alone_does_not_open_billing(string $page): void
    {
        $this->actingAs($this->staff('access admin panel'))
            ->get($this->urlOf($page))
            ->assertForbidden();
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function widgets(): array
    {
        return [
            'stats' => [BillingSaasStatsWidget::class],
            'revenue' => [RevenueChartWidget::class],
            'subscriptions' => [SubscriptionsChartWidget::class],
            'conversion' => [ConversionChartWidget::class],
        ];
    }

    /** The stats widget also sits on the main dashboard, which every staff role sees. */
    #[DataProvider('widgets')]
    public function test_billing_widgets_show_only_to_a_billing_admin(string $widget): void
    {
        $this->actingAs($this->staff('access admin panel'));
        $this->assertFalse($widget::canView());

        $this->actingAs($this->staff('access admin panel', 'manage billing'));
        $this->assertTrue($widget::canView());
    }

    private function urlOf(string $page): string
    {
        return is_subclass_of($page, Page::class) ? $page::getUrl() : $page::getUrl('index');
    }

    private function staff(string ...$permissions): User
    {
        Permission::findOrCreate('access admin panel');

        return User::factory()->create(['email_verified_at' => now()])->givePermissionTo($permissions);
    }
}
