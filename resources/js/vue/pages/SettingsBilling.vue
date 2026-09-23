<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDialog } from '@/composables/useDialog';
import { useLocalization } from '@/composables/useLocalization';
import { formatDate } from '@js/lib/dates';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';
import { CreditCard, Loader2 } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import type { Invoice, PaymentMethod, Subscription } from '../../types';

defineProps<{
    subscription: Subscription | null;
    lifetimePlans: { name: string | null }[];
    paymentMethod: PaymentMethod | null;
    invoices: Invoice[];
    billingPortalUrl: string;
}>();

/**
 * Congratulate whoever just came back from the payment page.
 *
 * The parameter is dropped from the URL straight away, so a refresh or a later
 * visit to this panel does not celebrate the same purchase again.
 */
onMounted(() => {
    const url = new URL(window.location.href);

    if (url.searchParams.get('checkout') !== 'success') {
        return;
    }

    toast.success(trans('You are all set!'), {
        description: trans('Your subscription is active. Welcome aboard.'),
    });

    // Through the router, not history.replaceState: Inertia keeps its own copy of
    // the URL and re-renders from it, so switching panel would toast again.
    url.searchParams.delete('checkout');
    router.replace({
        url: url.toString(),
        preserveState: true,
        preserveScroll: true,
    });
});

const isCancelling = ref(false);
const isResuming = ref(false);
const { confirm } = useDialog();

const { language } = useLocalization();

const longDate: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
};

function formatCurrency(amount: number, currency: string | null): string {
    const cur = currency?.toUpperCase() ?? 'USD';
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: cur,
    }).format(amount / 100);
}

function formatInterval(interval: string | null): string {
    if (!interval) return '';
    return interval === 'year' ? 'Yearly' : 'Monthly';
}

function ucfirst(value: string | null | undefined): string {
    if (!value) return '';
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function pad(value: number | null | undefined): string {
    return String(value ?? 0).padStart(2, '0');
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'paid':
            return 'default';
        case 'posted':
        case 'unpaid':
            return 'secondary';
        default:
            return 'outline';
    }
}

async function handleCancelSubscription() {
    if (
        await confirm({
            title: trans('Cancel subscription'),
            description: trans(
                'Are you sure you want to cancel your subscription? You will continue to have access until the end of your current billing period.',
            ),
            confirmLabel: trans('Cancel subscription'),
            cancelLabel: trans('Keep plan'),
            variant: 'destructive',
            icon: CreditCard,
        })
    ) {
        isCancelling.value = true;
        router.post(
            route('billing.subscription.cancel'),
            {},
            {
                onFinish: () => {
                    isCancelling.value = false;
                },
            },
        );
    }
}

function resumeSubscription() {
    isResuming.value = true;
    router.post(
        route('billing.subscription.resume'),
        {},
        {
            onFinish: () => {
                isResuming.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-8" data-testid="settings-billing-panel">
        <p class="text-muted-foreground text-sm">
            {{ $t('Manage your subscription, payment method, and invoices') }}
        </p>

        <!-- Lifetime plans are owned for good, alongside any subscription -->
        <div
            v-if="lifetimePlans.length"
            data-testid="lifetime-plans"
            class="space-y-1"
        >
            <h3 class="font-medium">{{ $t('Lifetime') }}</h3>
            <p
                v-for="(plan, index) in lifetimePlans"
                :key="index"
                class="text-foreground text-lg font-semibold"
            >
                {{ plan.name ?? $t('Unknown Plan') }}
            </p>
        </div>

        <template v-if="subscription">
            <div data-testid="subscription-section" class="space-y-8">
                <!-- Current plan -->
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-1">
                        <h3 class="font-medium">{{ $t('Current Plan') }}</h3>
                        <p
                            data-testid="plan-name"
                            class="text-foreground text-lg font-semibold"
                        >
                            {{ subscription.plan_name ?? $t('Unknown Plan') }}
                        </p>
                        <p class="text-muted-foreground text-sm">
                            {{ formatInterval(subscription.interval) }}
                            <!-- Trial, then trouble, then the ordinary dates -->
                            <template v-if="subscription.suspended">
                                &middot;
                                <span
                                    class="text-destructive"
                                    data-testid="subscription-suspended"
                                >
                                    {{
                                        $t(
                                            'Suspended — update your payment details to start it again',
                                        )
                                    }}
                                </span>
                            </template>
                            <template v-else-if="subscription.grace_ends_at">
                                &middot;
                                <span
                                    class="text-destructive"
                                    data-testid="subscription-grace"
                                >
                                    {{
                                        $t(
                                            'Payment failed — update your card by',
                                        )
                                    }}
                                    {{
                                        formatDate(
                                            subscription.grace_ends_at,
                                            language,
                                            longDate,
                                        )
                                    }}
                                    {{ $t('to keep this subscription') }}
                                </span>
                            </template>
                            <template
                                v-else-if="
                                    subscription.on_trial &&
                                    !subscription.cancelled_at
                                "
                            >
                                &middot;
                                <span data-testid="subscription-trial">
                                    {{ $t('Trial ends on') }}
                                    {{
                                        formatDate(
                                            subscription.trial_ends_at,
                                            language,
                                            longDate,
                                        )
                                    }}
                                </span>
                            </template>
                            <template v-else-if="subscription.cancelled_at">
                                &middot;
                                <span
                                    v-if="subscription.replaced_by_lifetime"
                                    data-testid="replaced-by-lifetime"
                                >
                                    {{ $t('Ends on') }}
                                    {{
                                        formatDate(
                                            subscription.ends_at,
                                            language,
                                            longDate,
                                        )
                                    }},
                                    {{ $t('replaced by your lifetime plan') }}
                                </span>
                                <span
                                    v-else
                                    class="text-destructive"
                                    data-testid="subscription-cancels-on"
                                >
                                    {{ $t('Cancels on') }}
                                    {{
                                        formatDate(
                                            subscription.ends_at,
                                            language,
                                            longDate,
                                        )
                                    }}
                                </span>
                            </template>
                            <template
                                v-else-if="subscription.current_period_ends_at"
                            >
                                &middot;
                                {{ $t('Renews on') }}
                                {{
                                    formatDate(
                                        subscription.current_period_ends_at,
                                        language,
                                        longDate,
                                    )
                                }}
                            </template>
                        </p>
                    </div>
                    <a :href="billingPortalUrl">
                        <Button
                            v-if="subscription.needs_payment_method"
                            size="sm"
                            data-testid="add-payment-method"
                        >
                            {{ $t('Add payment method') }}
                        </Button>
                        <Button v-else variant="outline" size="sm">
                            {{ $t('Adjust plan') }}
                        </Button>
                    </a>
                </div>

                <!-- Payment method -->
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-1">
                        <h3 class="font-medium">{{ $t('Payment Method') }}</h3>
                        <p
                            v-if="paymentMethod"
                            class="text-muted-foreground text-sm"
                        >
                            <template
                                v-if="
                                    paymentMethod.category === 'card' &&
                                    paymentMethod.details
                                "
                            >
                                {{ ucfirst(paymentMethod.details?.brand) }}
                                &bull;&bull;&bull;&bull;{{
                                    paymentMethod.details?.last4
                                }}
                                <template
                                    v-if="paymentMethod.details?.expMonth"
                                >
                                    &middot;
                                    {{ $t('Expires') }}
                                    {{ pad(paymentMethod.details.expMonth) }}/{{
                                        paymentMethod.details.expYear
                                    }}
                                </template>
                            </template>
                            <template
                                v-else-if="
                                    paymentMethod.category === 'wallet' &&
                                    paymentMethod.details
                                "
                            >
                                {{ ucfirst(paymentMethod.type) }}
                                <span v-if="paymentMethod.details?.email">
                                    {{ paymentMethod.details.email }}
                                </span>
                            </template>
                            <template
                                v-else-if="
                                    paymentMethod.category === 'bank' &&
                                    paymentMethod.details
                                "
                            >
                                {{
                                    paymentMethod.details?.bankName ??
                                    $t('Bank account')
                                }}
                                <template v-if="paymentMethod.details?.last4">
                                    &bull;&bull;&bull;&bull;{{
                                        paymentMethod.details.last4
                                    }}
                                </template>
                            </template>
                            <template v-else>
                                {{ $t('Payment method') }}
                            </template>
                        </p>
                        <p v-else class="text-muted-foreground text-sm">
                            {{ $t('No payment method on file') }}
                        </p>
                    </div>
                    <a :href="billingPortalUrl">
                        <Button variant="outline" size="sm">
                            {{ $t('Update') }}
                        </Button>
                    </a>
                </div>

                <!-- Invoices -->
                <div class="space-y-3">
                    <h3 class="font-medium">{{ $t('Invoices') }}</h3>

                    <div v-if="invoices.length > 0" class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-border border-b">
                                    <th
                                        class="text-muted-foreground pb-2 text-left font-medium"
                                    >
                                        {{ $t('Date') }}
                                    </th>
                                    <th
                                        class="text-muted-foreground pb-2 text-left font-medium"
                                    >
                                        {{ $t('Amount') }}
                                    </th>
                                    <th
                                        class="text-muted-foreground pb-2 text-left font-medium"
                                    >
                                        {{ $t('Status') }}
                                    </th>
                                    <th
                                        class="text-muted-foreground pb-2 text-right font-medium"
                                    >
                                        {{ $t('Invoice') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="invoice in invoices"
                                    :key="invoice.id"
                                    class="border-border border-b last:border-0"
                                >
                                    <td class="text-foreground py-3">
                                        {{
                                            formatDate(
                                                invoice.paid_at,
                                                language,
                                                longDate,
                                            )
                                        }}
                                    </td>
                                    <td class="text-foreground py-3">
                                        {{
                                            formatCurrency(
                                                invoice.total,
                                                invoice.currency,
                                            )
                                        }}
                                    </td>
                                    <td class="py-3">
                                        <Badge
                                            :variant="
                                                statusVariant(invoice.status)
                                            "
                                        >
                                            {{ invoice.status }}
                                        </Badge>
                                    </td>
                                    <td class="py-3 text-right">
                                        <a
                                            v-if="invoice.hosted_invoice_url"
                                            :href="invoice.hosted_invoice_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="text-primary/70 text-sm font-medium underline-offset-4 hover:underline"
                                        >
                                            {{ $t('View') }}
                                        </a>
                                        <span v-else class="text-foreground/50">
                                            &mdash;
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-muted-foreground text-sm">
                        {{ $t('No invoices yet') }}
                    </p>
                </div>

                <!-- Resume, when the plan is already cancelled -->
                <div
                    v-if="
                        subscription.cancelled_at &&
                        !subscription.replaced_by_lifetime
                    "
                    class="space-y-3"
                >
                    <div class="space-y-1">
                        <h3 class="font-medium">
                            {{ $t('Resume subscription') }}
                        </h3>
                        <p class="text-muted-foreground text-sm">
                            {{
                                $t(
                                    'Changed your mind? Resume your subscription to keep your access.',
                                )
                            }}
                        </p>
                    </div>
                    <Button
                        data-testid="resume-button"
                        size="sm"
                        :disabled="isResuming"
                        @click="resumeSubscription"
                    >
                        <Loader2
                            v-if="isResuming"
                            class="mr-2 size-4 animate-spin"
                        />
                        {{ $t('Resume plan') }}
                    </Button>
                </div>

                <!-- Otherwise, the way out; a subscription a lifetime plan
                     replaces is already ending -->
                <div v-else-if="!subscription.cancelled_at" class="space-y-3">
                    <div class="space-y-1">
                        <h3 class="font-medium">{{ $t('Cancellation') }}</h3>
                        <p class="text-muted-foreground text-sm">
                            {{
                                $t(
                                    'Your subscription will remain active until the end of the current billing period.',
                                )
                            }}
                        </p>
                    </div>
                    <Button
                        data-testid="cancel-button"
                        variant="destructive"
                        size="sm"
                        :disabled="isCancelling"
                        @click="handleCancelSubscription"
                    >
                        <Loader2
                            v-if="isCancelling"
                            class="mr-2 size-4 animate-spin"
                        />
                        {{ $t('Cancel subscription') }}
                    </Button>
                </div>
            </div>
        </template>

        <!-- Nothing bought yet -->
        <div
            v-else-if="!lifetimePlans.length"
            data-testid="no-subscription"
            class="border-border flex w-full flex-col items-center justify-center rounded-lg border border-dashed p-12 text-center"
        >
            <h3 class="text-foreground font-medium">
                {{ $t('No active subscription') }}
            </h3>
            <p class="text-muted-foreground mt-2 text-sm">
                {{ $t('Choose a plan to get started with all the features.') }}
            </p>
            <a :href="route('billing.plans')" class="mt-4">
                <Button>
                    {{ $t('View Plans') }}
                </Button>
            </a>
        </div>
    </div>
</template>
