<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Enums\InvoiceStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Settings\BillingSection;
use Tests\TestCase;

/**
 * What the billing panel in the settings modal says about the owner's plans.
 */
class BillingPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Product $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->customer = Customer::factory()->create(['user_id' => $this->user->id]);
        $this->pro = Product::factory()->create(['name' => 'Pro']);
        $this->actingAs($this->user);
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        return json_decode(json_encode(app(BillingSection::class)->props()), true);
    }

    private function subscribeToPro(array $attributes = []): Subscription
    {
        return Subscription::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->create(['product_id' => $this->pro->id])->id,
            ...$attributes,
        ]);
    }

    private function ownLifetimePro(): void
    {
        Payment::factory()->create([
            'customer_id' => $this->customer->id,
            'price_id' => Price::factory()->oneTime()->create([
                'product_id' => Product::factory()->lifetime($this->pro)->create(['name' => 'Lifetime'])->id,
            ])->id,
        ]);
    }

    public function test_the_lifetime_plans_owned_are_listed(): void
    {
        $this->ownLifetimePro();

        $this->assertSame(['Lifetime'], array_column($this->props()['lifetimePlans'], 'name'));
    }

    public function test_a_subscription_lifetime_replaces_says_so_once_its_end_is_scheduled(): void
    {
        $this->subscribeToPro(['cancelled_at' => now(), 'ends_at' => now()->addWeek()]);
        $this->ownLifetimePro();

        $this->assertTrue($this->props()['subscription']['replaced_by_lifetime']);
    }

    /** Lifetime paid, cancel call still being retried: the panel shows it renewing, as it is. */
    public function test_a_replaced_subscription_whose_cancellation_is_pending_is_shown_as_it_is(): void
    {
        $this->subscribeToPro();
        $this->ownLifetimePro();

        $this->assertFalse($this->props()['subscription']['replaced_by_lifetime']);
    }

    public function test_the_billing_page_needs_a_signed_in_user(): void
    {
        auth()->logout();

        $this->get(route('settings.billing'))->assertRedirect(route('login'));
    }

    public function test_an_owner_with_nothing_bought_sees_an_empty_panel(): void
    {
        $props = $this->props();

        $this->assertNull($props['subscription']);
        $this->assertNull($props['paymentMethod']);
        $this->assertSame([], $props['invoices']);
    }

    public function test_the_current_subscription_is_shown_with_its_plan(): void
    {
        $subscription = $this->subscribeToPro();

        $props = $this->props();

        $this->assertSame($subscription->id, $props['subscription']['id']);
        $this->assertSame('active', $props['subscription']['status']);
        $this->assertSame('Pro', $props['subscription']['plan_name']);
    }

    public function test_invoices_are_listed(): void
    {
        Invoice::factory()->count(3)->create(['customer_id' => $this->customer->id, 'status' => InvoiceStatus::Paid]);

        $this->assertCount(3, $this->props()['invoices']);
    }

    public function test_the_default_payment_method_is_shown(): void
    {
        PaymentMethod::factory()->visa()->default()->create([
            'customer_id' => $this->customer->id,
            'details' => ['brand' => 'visa', 'last4' => '4242', 'expMonth' => 12, 'expYear' => 2030],
        ]);

        $method = $this->props()['paymentMethod'];

        $this->assertSame('card', $method['type']);
        $this->assertSame('visa', $method['details']['brand']);
        $this->assertSame('4242', $method['details']['last4']);
    }
}
