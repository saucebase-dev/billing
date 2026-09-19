<?php

namespace Modules\Billing\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;

class BillingHistorySurvivesTest extends TestCase
{
    use RefreshDatabase;

    /** What was charged has to stay answerable after the account is gone. */
    public function test_deleting_a_user_detaches_the_customer_and_keeps_the_history(): void
    {
        $user = $this->createUser();
        $customer = Customer::factory()->create(['user_id' => $user->id]);
        $subscription = Subscription::factory()->create(['customer_id' => $customer->id]);
        $payment = Payment::factory()->create(['customer_id' => $customer->id, 'subscription_id' => $subscription->id]);
        $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'subscription_id' => $subscription->id]);

        $user->delete();

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'user_id' => null]);
        $this->assertDatabaseHas('subscriptions', ['id' => $subscription->id, 'customer_id' => $customer->id]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'customer_id' => $customer->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'customer_id' => $customer->id]);
    }
}
