<script setup lang="ts">
import { DangerZone, EmptyState } from '@/components/ui/saucebase';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useDialog } from '@saucebase/vue/composables/useDialog';
import { useLocalization } from '@saucebase/vue/composables/useLocalization';
import { formatDate } from '@saucebase/core/lib/dates';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { toast } from 'vue-sonner';
import { CreditCard, Loader2 } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import PlanFeatures from '../components/PlanFeatures.vue';
import { getIntervalLabel } from '../../lib/intervals';
import { formatMoney } from '../../lib/money';
import type {
    Invoice,
    LifetimePlan,
    OneTimePayment,
    PaymentMethod,
    Subscription,
} from '../../types';

const props = defineProps<{
    subscription: Subscription | null;
    lifetimePlans: LifetimePlan[];
    payments: OneTimePayment[];
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
    url.searchParams.delete('settings');
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

// Each lifetime plan card already shows its purchase; the table earns its
// place with a payment no card shows (a one-off) or a refund.
const showPayments = computed(
    () =>
        props.payments.length > props.lifetimePlans.length ||
        props.payments.some((payment) => payment.status === 'refunded'),
);

const longDate: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
};

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
            {{
                !subscription && lifetimePlans.length
                    ? $t('Your plan and payments')
                    : $t(
                          'Manage your subscription, payment method, and invoices',
                      )
            }}
        </p>

        <!-- Lifetime plans are owned for good, alongside any subscription -->
        <Card
            v-for="(plan, index) in lifetimePlans"
            :key="index"
            data-testid="lifetime-plan"
            class="gap-4 px-6"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="space-y-1">
                    <p
                        data-testid="lifetime-plan-kind"
                        class="text-muted-foreground text-xs font-medium tracking-wide uppercase"
                    >
                        {{ $t('Lifetime plan') }}
                    </p>
                    <p
                        data-testid="lifetime-plan-name"
                        class="text-foreground text-lg font-semibold"
                    >
                        {{ plan.name ?? $t('Unknown Plan') }}
                    </p>
                </div>
                <div class="text-right">
                    <p
                        data-testid="lifetime-plan-amount"
                        class="text-foreground font-semibold tabular-nums"
                    >
                        {{ formatMoney(plan.amount, plan.currency, language) }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        {{ $t('Paid once') }}
                    </p>
                </div>
            </div>

            <PlanFeatures :features="plan.features" />

            <p
                class="text-muted-foreground border-border border-t pt-4 text-sm"
            >
                <template v-if="plan.paid_at">
                    {{
                        $t('Bought on :date', {
                            date: formatDate(plan.paid_at, language, longDate),
                        })
                    }}
                    &middot;
                </template>
                {{ $t('Yours for good, no renewals.') }}
            </p>
        </Card>

        <template v-if="subscription">
            <div data-testid="subscription-section" class="space-y-8">
                <!-- Current plan -->
                <Card data-testid="subscription-plan" class="gap-4 px-6">
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <p
                                data-testid="plan-kind"
                                class="text-muted-foreground text-xs font-medium tracking-wide uppercase"
                            >
                                {{
                                    subscription.interval
                                        ? $t(':interval plan', {
                                              interval: $t(
                                                  getIntervalLabel(
                                                      subscription.interval,
                                                  ),
                                              ),
                                          })
                                        : $t('Subscription')
                                }}
                            </p>
                            <p
                                data-testid="plan-name"
                                class="text-foreground text-lg font-semibold"
                            >
                                {{
                                    subscription.plan_name ?? $t('Unknown Plan')
                                }}
                            </p>
                        </div>
                        <p
                            v-if="subscription.amount !== null"
                            data-testid="plan-amount"
                            class="text-foreground text-right font-semibold tabular-nums"
                        >
                            {{
                                formatMoney(
                                    subscription.amount,
                                    subscription.currency,
                                    language,
                                )
                            }}
                            <span
                                v-if="subscription.interval"
                                class="text-muted-foreground block text-xs font-normal"
                            >
                                {{
                                    (subscription.interval_count ?? 1) > 1
                                        ? $t('every :count :interval', {
                                              count: String(
                                                  subscription.interval_count,
                                              ),
                                              interval: $t(
                                                  `${subscription.interval}s`,
                                              ),
                                          })
                                        : `/ ${$t(subscription.interval)}`
                                }}
                            </span>
                        </p>
                    </div>

                    <PlanFeatures :features="subscription.features" />

                    <div
                        class="border-border flex items-center justify-between gap-4 border-t pt-4"
                    >
                        <p class="text-muted-foreground text-sm">
                            <!-- Trial, then trouble, then the ordinary dates -->
                            <template v-if="subscription.suspended">
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
                                <span
                                    class="text-destructive"
                                    data-testid="subscription-grace"
                                >
                                    {{
                                        $t(
                                            'Payment failed — update your card by :date to keep this subscription',
                                            {
                                                date: formatDate(
                                                    subscription.grace_ends_at,
                                                    language,
                                                    longDate,
                                                ),
                                            },
                                        )
                                    }}
                                </span>
                            </template>
                            <template
                                v-else-if="
                                    subscription.on_trial &&
                                    !subscription.cancelled_at
                                "
                            >
                                <span data-testid="subscription-trial">
                                    {{
                                        $t('Trial ends on :date', {
                                            date: formatDate(
                                                subscription.trial_ends_at,
                                                language,
                                                longDate,
                                            ),
                                        })
                                    }}
                                </span>
                            </template>
                            <template v-else-if="subscription.cancelled_at">
                                <span
                                    v-if="subscription.replaced_by_lifetime"
                                    data-testid="replaced-by-lifetime"
                                >
                                    {{
                                        $t(
                                            'Ends on :date, replaced by your lifetime plan',
                                            {
                                                date: formatDate(
                                                    subscription.ends_at,
                                                    language,
                                                    longDate,
                                                ),
                                            },
                                        )
                                    }}
                                </span>
                                <span
                                    v-else
                                    class="text-destructive"
                                    data-testid="subscription-cancels-on"
                                >
                                    {{
                                        $t('Cancels on :date', {
                                            date: formatDate(
                                                subscription.ends_at,
                                                language,
                                                longDate,
                                            ),
                                        })
                                    }}
                                </span>
                            </template>
                            <template
                                v-else-if="subscription.current_period_ends_at"
                            >
                                {{
                                    $t('Renews on :date', {
                                        date: formatDate(
                                            subscription.current_period_ends_at,
                                            language,
                                            longDate,
                                        ),
                                    })
                                }}
                            </template>
                        </p>
                        <Button
                            v-if="subscription.needs_payment_method"
                            as="a"
                            :href="billingPortalUrl"
                            size="sm"
                            data-testid="add-payment-method"
                        >
                            {{ $t('Add payment method') }}
                        </Button>
                        <Button
                            v-else
                            as="a"
                            :href="billingPortalUrl"
                            variant="outline"
                            size="sm"
                        >
                            {{ $t('Adjust plan') }}
                        </Button>
                    </div>
                </Card>

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
                                    {{
                                        $t('Expires :date', {
                                            date: `${pad(paymentMethod.details.expMonth)}/${paymentMethod.details.expYear}`,
                                        })
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
                    <Button
                        as="a"
                        :href="billingPortalUrl"
                        variant="outline"
                        size="sm"
                    >
                        {{ $t('Update') }}
                    </Button>
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
                                        class="text-muted-foreground pb-2 text-right font-medium"
                                    >
                                        {{ $t('Amount') }}
                                    </th>
                                    <th
                                        class="text-muted-foreground pb-2 pl-6 text-left font-medium"
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
                                    <td
                                        class="text-foreground py-3 text-right tabular-nums"
                                    >
                                        {{
                                            formatMoney(
                                                invoice.total,
                                                invoice.currency,
                                                language,
                                            )
                                        }}
                                    </td>
                                    <td class="py-3 pl-6">
                                        <Badge
                                            :variant="
                                                statusVariant(invoice.status)
                                            "
                                        >
                                            {{ $t(ucfirst(invoice.status)) }}
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
                <DangerZone v-else-if="!subscription.cancelled_at" :show-header="false">
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
                </DangerZone>
            </div>
        </template>

        <!-- Nothing bought yet -->
        <EmptyState
            v-else-if="!lifetimePlans.length"
            :title="$t('No active subscription')"
            :description="
                $t('Choose a plan to get started with all the features.')
            "
            data-testid="no-subscription"
        >
            <template #icon><CreditCard /></template>
            <Button as="a" :href="route('billing.plans')">
                {{ $t('View Plans') }}
            </Button>
        </EmptyState>

        <!-- One-time payments: a lifetime plan has no invoice to list -->
        <div v-if="showPayments" class="space-y-3" data-testid="payments">
            <h3 class="font-medium">{{ $t('Payments') }}</h3>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-border border-b">
                        <th
                            class="text-muted-foreground pb-2 text-left font-medium"
                        >
                            {{ $t('Date') }}
                        </th>
                        <th
                            class="text-muted-foreground pb-2 text-right font-medium"
                        >
                            {{ $t('Amount') }}
                        </th>
                        <th
                            class="text-muted-foreground pb-2 pl-6 text-left font-medium"
                        >
                            {{ $t('Status') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="payment in payments"
                        :key="payment.id"
                        :data-testid="`payment-row-${payment.id}`"
                        class="border-border border-b last:border-0"
                    >
                        <td class="text-foreground py-3">
                            {{
                                formatDate(payment.paid_at, language, longDate)
                            }}
                        </td>
                        <td
                            class="text-foreground py-3 text-right tabular-nums"
                        >
                            {{
                                formatMoney(
                                    payment.amount,
                                    payment.currency,
                                    language,
                                )
                            }}
                        </td>
                        <td class="py-3 pl-6">
                            <Badge
                                :variant="
                                    payment.status === 'succeeded'
                                        ? 'default'
                                        : 'outline'
                                "
                            >
                                {{
                                    payment.status === 'succeeded'
                                        ? $t('Paid')
                                        : $t('Refunded')
                                }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
