<?php

namespace Modules\Billing\Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Billing\Filament\Pages\BillingSettings as BillingSettingsPage;
use Modules\Billing\Settings\BillingSettings;
use Tests\TestCase;

class BillingSettingsAdminPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::ADMIN);

        $this->actingAs($admin);
    }

    /** The currency is stored as its code, so the form must hand back a string. */
    public function test_administrator_can_save_billing_settings(): void
    {
        $this->actingAsAdmin();

        Livewire::test(BillingSettingsPage::class)
            ->fillForm([
                'gateway' => 'stripe',
                'currency' => 'GBP',
                'redirect_to_gateway' => true,
                'checkout_abandon_after_minutes' => 45,
                'checkout_expire_after_minutes' => 120,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $settings = new BillingSettings;

        $this->assertSame('GBP', $settings->currency);
        $this->assertSame('stripe', $settings->gateway);
        $this->assertSame(45, $settings->checkout_abandon_after_minutes);
    }
}
