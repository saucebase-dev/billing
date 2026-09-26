<?php

namespace Modules\Billing\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Data\Webhook\CheckoutSessionData;
use Modules\Billing\Data\Webhook\CustomerDefaultsData;
use Modules\Billing\Data\Webhook\InvoiceData;
use Modules\Billing\Data\Webhook\InvoicePaymentData;
use Modules\Billing\Data\Webhook\PaymentMethodChangeData;
use Modules\Billing\Data\Webhook\RefundData;
use Modules\Billing\Data\Webhook\SubscriptionStateData;
use Modules\Billing\Enums\Currency;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Enums\WebhookEventType;
use Modules\Billing\Services\Gateways\StripeEventMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

/**
 * Everything the module knows about Stripe's payload shapes lives in the mapper,
 * so this is where each quirk is pinned down.
 */
class StripeEventMapperTest extends TestCase
{
    // The default currency is a billing setting.
    use RefreshDatabase;

    /** @param  array<string, mixed>  $object */
    private function subscription(array $object): SubscriptionStateData
    {
        $data = StripeEventMapper::map(WebhookEventType::SubscriptionUpdated, ['id' => 'sub_1', 'customer' => 'cus_1', ...$object]);
        $this->assertInstanceOf(SubscriptionStateData::class, $data);

        return $data;
    }

    public function test_each_event_type_maps_to_its_data_class(): void
    {
        $samples = [
            WebhookEventType::CheckoutCompleted->value => ['id' => 'cs_1', 'currency' => 'eur', 'amount_total' => 100],
            WebhookEventType::SubscriptionUpdated->value => ['id' => 'sub_1'],
            WebhookEventType::SubscriptionDeleted->value => ['id' => 'sub_1'],
            WebhookEventType::SubscriptionTrialWillEnd->value => ['id' => 'sub_1'],
            WebhookEventType::PaymentSucceeded->value => ['id' => 'in_1', 'currency' => 'eur'],
            WebhookEventType::PaymentFailed->value => ['id' => 'in_1', 'currency' => 'eur'],
            WebhookEventType::InvoicePaid->value => ['id' => 'in_1', 'currency' => 'eur'],
            WebhookEventType::PaymentRefunded->value => ['id' => 'ch_1'],
            WebhookEventType::PaymentMethodAttached->value => ['id' => 'pm_1'],
            WebhookEventType::PaymentMethodDetached->value => ['id' => 'pm_1'],
            WebhookEventType::CustomerUpdated->value => ['id' => 'cus_1'],
        ];

        foreach (WebhookEventType::cases() as $type) {
            $this->assertInstanceOf($type->dataClass(), StripeEventMapper::map($type, $samples[$type->value]), $type->value);
        }
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function checkouts(): array
    {
        return [
            'paid and complete' => [['status' => 'complete', 'payment_status' => 'paid'], true],
            'trial or full discount' => [['status' => 'complete', 'payment_status' => 'no_payment_required'], true],
            'delayed payment not settled' => [['status' => 'complete', 'payment_status' => 'unpaid'], false],
            'still open' => [['status' => 'open', 'payment_status' => 'unpaid'], false],
            'expired' => [['status' => 'expired', 'payment_status' => 'unpaid'], false],
        ];
    }

    /** @param  array<string, mixed>  $session */
    #[DataProvider('checkouts')]
    public function test_only_a_completed_and_settled_checkout_is_fulfillable(array $session, bool $fulfillable): void
    {
        $data = StripeEventMapper::checkoutSession(['id' => 'cs_1', 'currency' => 'eur', 'amount_total' => 100, ...$session]);

        $this->assertSame($fulfillable, $data->fulfillable);
    }

    public function test_a_checkout_references_its_subscription_or_payment_for_the_card(): void
    {
        $subscription = StripeEventMapper::checkoutSession(['id' => 'cs_1', 'subscription' => 'sub_1', 'currency' => 'eur']);
        $oneOff = StripeEventMapper::checkoutSession(['id' => 'cs_2', 'payment_intent' => 'pi_1', 'currency' => 'usd', 'amount_total' => 500]);

        $this->assertSame('sub_1', $subscription->paymentMethodReference);
        $this->assertSame('pi_1', $oneOff->paymentMethodReference);
        $this->assertSame('pi_1', $oneOff->providerPaymentId);
        $this->assertSame(Currency::USD, $oneOff->currency);
        $this->assertSame(500, $oneOff->amount);
    }

    /** @return array<string, array{string, ?SubscriptionStatus}> */
    public static function statuses(): array
    {
        return [
            'active' => ['active', SubscriptionStatus::Active],
            'a trial is active' => ['trialing', SubscriptionStatus::Active],
            'past due' => ['past_due', SubscriptionStatus::PastDue],
            'unpaid is suspended' => ['unpaid', SubscriptionStatus::Suspended],
            'canceled' => ['canceled', SubscriptionStatus::Cancelled],
            'incomplete expired' => ['incomplete_expired', SubscriptionStatus::Cancelled],
            'incomplete' => ['incomplete', SubscriptionStatus::Pending],
            'paused' => ['paused', SubscriptionStatus::Pending],
            'unknown leaves it alone' => ['something_new', null],
        ];
    }

    #[DataProvider('statuses')]
    public function test_stripe_statuses_become_the_modules(string $stripe, ?SubscriptionStatus $expected): void
    {
        $this->assertSame($expected, $this->subscription(['status' => $stripe])->status);
    }

    /** Stripe moved the period onto the item in API 2025-03-31; older endpoints still send it on top. */
    public function test_the_period_is_read_from_the_item_or_the_subscription(): void
    {
        $item = $this->subscription(['items' => ['data' => [['current_period_start' => 100, 'current_period_end' => 200, 'price' => ['id' => 'price_1']]]]]);
        $top = $this->subscription(['current_period_start' => 300, 'current_period_end' => 400]);

        $this->assertSame(100, $item->periodStartsAt->timestamp);
        $this->assertSame(200, $item->periodEndsAt->timestamp);
        $this->assertSame('price_1', $item->providerPriceId);
        $this->assertSame(300, $top->periodStartsAt->timestamp);
    }

    public function test_trial_dates_come_through(): void
    {
        $data = $this->subscription(['trial_start' => 100, 'trial_end' => 200]);

        $this->assertSame(100, $data->trialStartsAt->timestamp);
        $this->assertSame(200, $data->trialEndsAt->timestamp);
    }

    public function test_a_cancellation_at_period_end_ends_with_the_period(): void
    {
        $data = $this->subscription(['cancel_at_period_end' => true, 'current_period_end' => 400, 'cancel_at' => 999]);

        $this->assertTrue($data->cancellationScheduled);
        $this->assertSame(400, $data->endsAt->timestamp);
    }

    public function test_a_cancellation_on_a_date_ends_then(): void
    {
        $data = $this->subscription(['cancel_at_period_end' => false, 'cancel_at' => 999]);

        $this->assertTrue($data->cancellationScheduled);
        $this->assertSame(999, $data->endsAt->timestamp);
    }

    public function test_a_cancellation_undone_clears_it(): void
    {
        $this->assertFalse($this->subscription(['cancel_at_period_end' => false, 'cancel_at' => null])->cancellationScheduled);
    }

    public function test_a_cancellation_not_mentioned_is_left_alone(): void
    {
        $this->assertInstanceOf(Optional::class, $this->subscription([])->cancellationScheduled);
    }

    public function test_a_payment_method_cleared_is_not_the_same_as_not_mentioned(): void
    {
        $this->assertNull($this->subscription(['default_payment_method' => null])->paymentMethodReference);
        $this->assertInstanceOf(Optional::class, $this->subscription([])->paymentMethodReference);
        $this->assertSame('pm_1', $this->subscription(['default_payment_method' => 'pm_1'])->paymentMethodReference);
    }

    public function test_a_payment_is_identified_by_its_intent_and_the_amount_follows_the_outcome(): void
    {
        $invoice = ['id' => 'in_1', 'payment_intent' => 'pi_1', 'customer' => 'cus_1', 'currency' => 'eur', 'amount_paid' => 2900, 'amount_due' => 3900];

        $paid = StripeEventMapper::map(WebhookEventType::PaymentSucceeded, $invoice);
        $failed = StripeEventMapper::map(WebhookEventType::PaymentFailed, $invoice);

        $this->assertInstanceOf(InvoicePaymentData::class, $paid);
        $this->assertSame('pi_1', $paid->providerPaymentId);
        $this->assertSame(2900, $paid->amount);
        $this->assertInstanceOf(InvoicePaymentData::class, $failed);
        $this->assertSame(3900, $failed->amount);
    }

    /** Newer API versions nest the subscription under `parent`. */
    public function test_the_invoice_subscription_is_found_under_parent_too(): void
    {
        $data = StripeEventMapper::map(WebhookEventType::PaymentSucceeded, [
            'id' => 'in_1', 'currency' => 'eur',
            'parent' => ['subscription_details' => ['subscription' => 'sub_nested']],
        ]);

        $this->assertInstanceOf(InvoicePaymentData::class, $data);
        $this->assertSame('sub_nested', $data->providerSubscriptionId);
    }

    /** Proration lines cover only part of the period, so the period spans every line. */
    public function test_an_invoice_period_spans_all_its_lines(): void
    {
        $data = StripeEventMapper::map(WebhookEventType::InvoicePaid, [
            'id' => 'in_1', 'currency' => 'eur', 'total' => 100,
            'lines' => ['data' => [['period' => ['start' => 300, 'end' => 400]], ['period' => ['start' => 100, 'end' => 500]]]],
        ]);

        $this->assertInstanceOf(InvoiceData::class, $data);
        $this->assertSame(100, $data->periodStartsAt->timestamp);
        $this->assertSame(500, $data->periodEndsAt->timestamp);
    }

    public function test_only_a_full_refund_is_a_refund(): void
    {
        $full = StripeEventMapper::map(WebhookEventType::PaymentRefunded, ['id' => 'ch_1', 'payment_intent' => 'pi_1', 'refunded' => true, 'amount_refunded' => 100]);
        $partial = StripeEventMapper::map(WebhookEventType::PaymentRefunded, ['id' => 'ch_1', 'payment_intent' => 'pi_1', 'refunded' => false, 'amount_refunded' => 50]);

        $this->assertInstanceOf(RefundData::class, $full);
        $this->assertTrue($full->fullyRefunded);
        $this->assertInstanceOf(RefundData::class, $partial);
        $this->assertFalse($partial->fullyRefunded);
        $this->assertSame(50, $partial->amountRefunded);
    }

    public function test_a_payment_method_change_keeps_its_own_id_for_removal(): void
    {
        $data = StripeEventMapper::map(WebhookEventType::PaymentMethodDetached, ['id' => 'pm_1', 'customer' => null]);

        $this->assertInstanceOf(PaymentMethodChangeData::class, $data);
        $this->assertSame('pm_1', $data->providerPaymentMethodId);
    }

    public function test_a_customers_default_cleared_is_not_the_same_as_not_mentioned(): void
    {
        $cleared = StripeEventMapper::map(WebhookEventType::CustomerUpdated, ['id' => 'cus_1', 'invoice_settings' => ['default_payment_method' => null]]);
        $silent = StripeEventMapper::map(WebhookEventType::CustomerUpdated, ['id' => 'cus_1']);

        $this->assertInstanceOf(CustomerDefaultsData::class, $cleared);
        $this->assertNull($cleared->defaultPaymentMethodReference);
        $this->assertInstanceOf(CustomerDefaultsData::class, $silent);
        $this->assertInstanceOf(Optional::class, $silent->defaultPaymentMethodReference);
    }

    public function test_an_unknown_currency_falls_back_to_the_default(): void
    {
        $data = StripeEventMapper::checkoutSession(['id' => 'cs_1', 'currency' => 'xyz']);

        $this->assertInstanceOf(CheckoutSessionData::class, $data);
        $this->assertSame(Currency::default(), $data->currency);
    }
}
