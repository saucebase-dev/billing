<?php

namespace Modules\Billing\Settings;

use Illuminate\Support\Facades\Auth;
use Modules\Billing\Enums\InvoiceStatus;
use Modules\Billing\Enums\SubscriptionStatus;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\PurchaseEligibility;
use Saucebase\Core\Settings\SettingsSection;

/**
 * Subscription, payment method and invoices for the signed-in user.
 *
 * User-scoped rather than workspace-scoped, so it is offered everywhere — there
 * is no `visible()` override.
 */
class BillingSection extends SettingsSection
{
    public function __construct(
        private PurchaseEligibility $eligibility,
    ) {}

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

        $customer = $user->billingCustomer;

        if (! $customer) {
            return [
                'subscription' => null,
                'lifetimePlans' => [],
                'paymentMethod' => null,
                'invoices' => [],
                'billingPortalUrl' => route('billing.portal'),
            ];
        }

        $subscription = $customer->currentSubscription()?->load(['price.product', 'paymentMethod']);

        $defaultPaymentMethod = $customer
            ->paymentMethods()
            ->where('is_default', true)
            ->first();

        $invoices = Invoice::where('customer_id', $customer->id)
            ->whereIn('status', [InvoiceStatus::Paid, InvoiceStatus::Posted, InvoiceStatus::Unpaid])
            ->orderByDesc('paid_at')
            ->limit(20)
            ->get();

        // A lifetime plan covers it, so there is no card to chase.
        $replacedByLifetime = $subscription?->cancelled_at !== null
            && $this->eligibility->isReplacedByLifetime($subscription);

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
                // Only once the provider accepted the cancellation: until then
                // it still renews, and the panel says so.
                'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
                'on_trial' => $subscription->trial_ends_at?->isFuture() ?? false,
                // Only while it is running: a suspended plan says so instead.
                'grace_ends_at' => $subscription->status === SubscriptionStatus::PastDue && ! $replacedByLifetime
                    ? $subscription->grace_ends_at?->toISOString()
                    : null,
                'suspended' => $subscription->status === SubscriptionStatus::Suspended && ! $replacedByLifetime,
                // A nudge, never a decision: the provider decides what a trial
                // without payment details does when it ends.
                'needs_payment_method' => ! $subscription->hasPaymentMethod(),
                'replaced_by_lifetime' => $replacedByLifetime,
            ] : null,
            'lifetimePlans' => $customer->lifetimePurchases()
                ->map(fn ($payment) => ['name' => $payment->price?->plan?->name])
                ->values(),
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
