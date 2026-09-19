<?php

namespace Modules\Billing\Settings;

use Illuminate\Support\Facades\Auth;
use Modules\Billing\Enums\InvoiceStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Customer;
use Modules\Billing\Models\Invoice;
use Saucebase\Core\Settings\SettingsSection;

/**
 * Subscription, payment method and invoices for the signed-in user.
 *
 * User-scoped rather than workspace-scoped, so it is offered everywhere — there
 * is no `visible()` override.
 */
class BillingSection extends SettingsSection
{
    public function slug(): string
    {
        return 'billing';
    }

    public function title(): string
    {
        return __('Billing');
    }

    public function icon(): ?string
    {
        return 'billing';
    }

    public function order(): int
    {
        return 30;
    }

    public function component(): string
    {
        return 'Billing::SettingsBilling';
    }

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $user = Auth::user();

        /** @var Customer|null $customer */
        $customer = $user->billingCustomer;

        if (! $customer) {
            return [
                'subscription' => null,
                'paymentMethod' => null,
                'invoices' => [],
                'billingPortalUrl' => route('billing.portal'),
            ];
        }

        $subscription = $customer
            ->subscriptions()
            ->with(['price.product', 'paymentMethod'])
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->latest()
            ->first();

        $defaultPaymentMethod = $customer
            ->paymentMethods()
            ->where('is_default', true)
            ->first();

        $invoices = Invoice::where('customer_id', $customer->id)
            ->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Posted, InvoiceStatus::Unpaid])
            ->orderByDesc('paid_at')
            ->limit(20)
            ->get();

        // TODO: move it to a resource?

        return [
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'status' => $subscription->status->value,
                'current_period_starts_at' => $subscription->current_period_starts_at?->toISOString(),
                'current_period_ends_at' => $subscription->current_period_ends_at?->toISOString(),
                'cancelled_at' => $subscription->cancelled_at?->toISOString(),
                'ends_at' => $subscription->ends_at?->toISOString(),
                'plan_name' => $subscription->price?->product?->name,
                'interval' => $subscription->price?->interval,
            ] : null,
            'paymentMethod' => $defaultPaymentMethod ? [
                'type' => $defaultPaymentMethod->type->value,
                'category' => $defaultPaymentMethod->type->category(),
                'details' => $defaultPaymentMethod->details,
            ] : null,
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'total' => $invoice->total,
                'currency' => $invoice->currency->value,
                'status' => $invoice->status->value,
                'paid_at' => $invoice->paid_at?->toISOString(),
                'hosted_invoice_url' => $invoice->hosted_invoice_url,
            ])->values(),
            'billingPortalUrl' => route('billing.portal'),
        ];
    }
}
