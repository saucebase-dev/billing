<?php

namespace Modules\Billing\Tests\Feature;

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
        $admin->assignRole('admin');

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

    /** Billing by default; offered by name from the settings sections the app has. */
    public function test_an_admin_chooses_the_section_a_buyer_lands_on_after_paying(): void
    {
        $this->actingAsAdmin();
        $this->assertSame('billing', (new BillingSettings)->after_checkout_section);

        Livewire::test(BillingSettingsPage::class)
            ->assertFormFieldExists('after_checkout_section')
            ->fillForm(['after_checkout_section' => 'profile'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('profile', (new BillingSettings)->after_checkout_section);

        Livewire::test(BillingSettingsPage::class)
            ->fillForm(['after_checkout_section' => 'no-such-section'])
            ->call('save')
            ->assertHasFormErrors(['after_checkout_section']);
    }

    public function test_an_admin_sets_the_grace_period_and_the_trial_rule(): void
    {
        $this->actingAsAdmin();

        Livewire::test(BillingSettingsPage::class)
            ->fillForm([
                'gateway' => 'stripe',
                'currency' => 'EUR',
                'checkout_abandon_after_minutes' => 45,
                'checkout_expire_after_minutes' => 120,
                'grace_period_days' => 7,
                'trial_requires_payment_method' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = new BillingSettings;
        $this->assertSame(7, $settings->grace_period_days);
        $this->assertFalse($settings->trial_requires_payment_method);
    }
}
