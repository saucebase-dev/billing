<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Enums\InvoiceStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\PaymentMethod;
use Modules\Billing\Models\Price;
use Modules\Billing\Models\Product;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Settings\BillingSection;
use Modules\Billing\Tests\Support\Traits\BillsTheUser;
use Tests\TestCase;

/**
 * What the billing panel in the settings modal says about the owner's plans.
 */
class BillingPanelTest extends TestCase
{
    use BillsTheUser;
    use RefreshDatabase;

    private User $user;

    private Customer $customer;

    private Product $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        $this->customer = Customer::factory()->for($this->user, 'owner')->create();
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

    /** The subscription card shows its price and what the plan includes, like the lifetime one. */
    public function test_a_subscription_carries_its_price_and_features(): void
    {
        $this->pro->update(['features' => ['Unlimited projects', 'Webhooks']]);
        $subscription = $this->subscribeToPro();

        $props = $this->props()['subscription'];

        $this->assertSame($subscription->price->amount, $props['amount']);
        $this->assertSame($subscription->price->currency->value, $props['currency']);
        $this->assertSame(['Unlimited projects', 'Webhooks'], $props['features']);
    }

    /** A quarterly price is "every 3 months", not "/ month". */
    public function test_a_subscription_carries_its_interval_count(): void
    {
        $subscription = $this->subscribeToPro();
        $subscription->price->update(['interval_count' => 3]);

        $this->assertSame(3, $this->props()['subscription']['interval_count']);
    }

    /** A lifetime plan shows what was paid and when, like a subscription shows its renewal. */
    public function test_a_lifetime_plan_carries_its_purchase(): void
    {
        $this->ownLifetimePro();
        $payment = Payment::latest('id')->first();

        $plan = $this->props()['lifetimePlans'][0];

        $this->assertSame($payment->amount, $plan['amount']);
        $this->assertSame($payment->currency->value, $plan['currency']);
        $this->assertSame($payment->created_at->toISOString(), $plan['paid_at']);
        $this->assertSame($payment->price->plan->features ?? [], $plan['features']);
    }

    /** One-time payments have no invoice to list, so they get their own list; refunds included. */
    public function test_one_time_payments_are_listed_and_subscription_payments_are_not(): void
    {
        $this->ownLifetimePro();
        $refunded = Payment::factory()->refunded()->create(['customer_id' => $this->customer->id, 'price_id' => Price::factory()->oneTime()]);
        Payment::factory()->create(['customer_id' => $this->customer->id, 'subscription_id' => $this->subscribeToPro()->id]);

        $payments = $this->props()['payments'];

        $this->assertCount(2, $payments);
        $this->assertSame($refunded->id, $payments[0]['id']);
        $this->assertSame('refunded', $payments[0]['status']);
    }

    public function test_a_subscription_lifetime_replaces_says_so_once_its_end_is_scheduled(): void
    {
        $this->subscribeToPro(['cancelled_at' => now(), 'ends_at' => now()->addWeek()]);
        $this->ownLifetimePro();

        $this->assertTrue($this->props()['subscription']['replaced_by_lifetime']);
    }

    /** Lifetime covers it, so there is no card to chase. */
    public function test_a_replaced_subscription_that_fell_behind_is_not_chased(): void
    {
        $this->subscribeToPro(['status' => SubscriptionStatus::Suspended, 'grace_ends_at' => now()->subDay(), 'cancelled_at' => now(), 'ends_at' => now()->addWeek()]);
        $this->ownLifetimePro();

        $props = $this->props()['subscription'];

        $this->assertTrue($props['replaced_by_lifetime']);
        $this->assertFalse($props['suspended']);
        $this->assertNull($props['grace_ends_at']);
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

    public function test_a_trial_says_when_it_ends(): void
    {
        $this->subscribeToPro(['trial_starts_at' => now()->subDay(), 'trial_ends_at' => now()->addDays(13)]);

        $props = $this->props();

        $this->assertTrue($props['subscription']['on_trial']);
        $this->assertNotNull($props['subscription']['trial_ends_at']);
    }

    public function test_a_finished_trial_is_no_longer_a_trial(): void
    {
        $this->subscribeToPro(['trial_starts_at' => now()->subMonth(), 'trial_ends_at' => now()->subDay()]);

        $this->assertFalse($this->props()['subscription']['on_trial']);
    }

    public function test_falling_behind_shows_the_date_access_ends(): void
    {
        $this->subscribeToPro(['status' => SubscriptionStatus::PastDue, 'grace_ends_at' => now()->addDays(3)]);

        $props = $this->props();

        $this->assertNotNull($props['subscription']['grace_ends_at']);
        $this->assertFalse($props['subscription']['suspended']);
    }

    /** A suspended plan is still theirs: the panel has to say so, and how to fix it. */
    public function test_a_suspended_subscription_is_still_shown(): void
    {
        $this->subscribeToPro(['status' => SubscriptionStatus::Suspended, 'grace_ends_at' => now()->subDay()]);

        $props = $this->props();

        $this->assertSame('Pro', $props['subscription']['plan_name']);
        $this->assertTrue($props['subscription']['suspended']);
    }

    public function test_a_trial_without_a_card_is_asked_for_one(): void
    {
        $this->subscribeToPro(['trial_ends_at' => now()->addDays(13), 'payment_method_id' => null]);

        $this->assertTrue($this->props()['subscription']['needs_payment_method']);
    }

    public function test_a_subscription_with_a_card_is_not_asked_for_one(): void
    {
        $method = PaymentMethod::factory()->create(['customer_id' => $this->customer->id]);
        $this->subscribeToPro(['trial_ends_at' => now()->addDays(13), 'payment_method_id' => $method->id]);

        $this->assertFalse($this->props()['subscription']['needs_payment_method']);
    }
}
