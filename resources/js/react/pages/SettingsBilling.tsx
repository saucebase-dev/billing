import { DangerZone, EmptyState } from '@/components/ui/saucebase';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { router } from '@inertiajs/react';
import { formatDate } from '@saucebase/core/lib/dates';
import { useDialog } from '@saucebase/react/hooks/useDialog';
import { useT, useTranslation } from '@saucebase/react/i18n';
import { CreditCard, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import PlanFeatures from '../components/PlanFeatures';
import { getIntervalLabel } from '../../lib/intervals';
import { formatMoney } from '../../lib/money';
import type {
    Invoice,
    LifetimePlan,
    OneTimePayment,
    PaymentMethod,
    Subscription,
} from '../../types';

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

function PaymentMethodLine({
    paymentMethod,
}: {
    paymentMethod: PaymentMethod;
}) {
    const t = useT();
    const details = paymentMethod.details;

    if (paymentMethod.category === 'card' && details) {
        return (
            <>
                {ucfirst(details.brand)}
                &bull;&bull;&bull;&bull;{details.last4}
                {details.expMonth && (
                    <>
                        {' '}
                        &middot;{' '}
                        {t('Expires :date', {
                            date: `${pad(details.expMonth)}/${details.expYear}`,
                        })}
                    </>
                )}
            </>
        );
    }

    if (paymentMethod.category === 'wallet' && details) {
        return (
            <>
                {ucfirst(paymentMethod.type)}
                {details.email && <span> {details.email}</span>}
            </>
        );
    }

    if (paymentMethod.category === 'bank' && details) {
        return (
            <>
                {details.bankName ?? t('Bank account')}
                {details.last4 && <>&bull;&bull;&bull;&bull;{details.last4}</>}
            </>
        );
    }

    return <>{t('Payment method')}</>;
}

export default function SettingsBilling({
    subscription,
    lifetimePlans,
    payments,
    paymentMethod,
    invoices,
    billingPortalUrl,
}: {
    subscription: Subscription | null;
    lifetimePlans: LifetimePlan[];
    payments: OneTimePayment[];
    paymentMethod: PaymentMethod | null;
    invoices: Invoice[];
    billingPortalUrl: string;
}) {
    const { t, locale } = useTranslation();
    const { confirm } = useDialog();
    const [isCancelling, setIsCancelling] = useState(false);
    const [isResuming, setIsResuming] = useState(false);

    // Each lifetime plan card already shows its purchase; the table earns its
    // place with a payment no card shows (a one-off) or a refund.
    const showPayments = useMemo(
        () =>
            payments.length > lifetimePlans.length ||
            payments.some((payment) => payment.status === 'refunded'),
        [payments, lifetimePlans],
    );

    /**
     * Congratulate whoever just came back from the payment page.
     *
     * The parameter is dropped from the URL straight away, so a refresh or a
     * later visit to this panel does not celebrate the same purchase again.
     */
    useEffect(() => {
        const url = new URL(window.location.href);

        if (url.searchParams.get('checkout') !== 'success') {
            return;
        }

        toast.success(t('You are all set!'), {
            description: t('Your subscription is active. Welcome aboard.'),
        });

        // Through the router, not history.replaceState: Inertia keeps its own
        // copy of the URL and re-renders from it, so switching panel would
        // toast again.
        url.searchParams.delete('checkout');
        url.searchParams.delete('settings');
        router.replace({
            url: url.toString(),
            preserveState: true,
            preserveScroll: true,
        });
    }, [t]);

    async function handleCancelSubscription() {
        const confirmed = await confirm({
            title: t('Cancel subscription'),
            description: t(
                'Are you sure you want to cancel your subscription? You will continue to have access until the end of your current billing period.',
            ),
            confirmLabel: t('Cancel subscription'),
            cancelLabel: t('Keep plan'),
            variant: 'destructive',
            icon: CreditCard,
        });

        if (!confirmed) {
            return;
        }

        setIsCancelling(true);
        router.post(
            route('billing.subscription.cancel'),
            {},
            { onFinish: () => setIsCancelling(false) },
        );
    }

    function resumeSubscription() {
        setIsResuming(true);
        router.post(
            route('billing.subscription.resume'),
            {},
            { onFinish: () => setIsResuming(false) },
        );
    }

    return (
        <div className="space-y-8" data-testid="settings-billing-panel">
            <p className="text-muted-foreground text-sm">
                {!subscription && lifetimePlans.length
                    ? t('Your plan and payments')
                    : t('Manage your subscription, payment method, and invoices')}
            </p>

            {/* Lifetime plans are owned for good, alongside any subscription */}
            {lifetimePlans.map((plan, index) => (
                <Card
                    key={index}
                    data-testid="lifetime-plan"
                    className="gap-4 px-6"
                >
                    <div className="flex items-start justify-between gap-4">
                        <div className="space-y-1">
                            <p
                                data-testid="lifetime-plan-kind"
                                className="text-muted-foreground text-xs font-medium tracking-wide uppercase"
                            >
                                {t('Lifetime plan')}
                            </p>
                            <p
                                data-testid="lifetime-plan-name"
                                className="text-foreground text-lg font-semibold"
                            >
                                {plan.name ?? t('Unknown Plan')}
                            </p>
                        </div>
                        <div className="text-right">
                            <p
                                data-testid="lifetime-plan-amount"
                                className="text-foreground font-semibold tabular-nums"
                            >
                                {formatMoney(plan.amount, plan.currency, locale)}
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {t('Paid once')}
                            </p>
                        </div>
                    </div>

                    <PlanFeatures features={plan.features} />

                    <p className="text-muted-foreground border-border border-t pt-4 text-sm">
                        {plan.paid_at && (
                            <>
                                {t('Bought on :date', {
                                    date: formatDate(
                                        plan.paid_at,
                                        locale,
                                        longDate,
                                    ),
                                })}{' '}
                                &middot;{' '}
                            </>
                        )}
                        {t('Yours for good, no renewals.')}
                    </p>
                </Card>
            ))}

            {subscription ? (
                <div data-testid="subscription-section" className="space-y-8">
                    {/* Current plan */}
                    <Card data-testid="subscription-plan" className="gap-4 px-6">
                        <div className="flex items-start justify-between gap-4">
                            <div className="space-y-1">
                                <p
                                    data-testid="plan-kind"
                                    className="text-muted-foreground text-xs font-medium tracking-wide uppercase"
                                >
                                    {subscription.interval
                                        ? t(':interval plan', {
                                              interval: t(
                                                  getIntervalLabel(
                                                      subscription.interval,
                                                  ),
                                              ),
                                          })
                                        : t('Subscription')}
                                </p>
                                <p
                                    data-testid="plan-name"
                                    className="text-foreground text-lg font-semibold"
                                >
                                    {subscription.plan_name ?? t('Unknown Plan')}
                                </p>
                            </div>
                            {subscription.amount !== null && (
                                <p
                                    data-testid="plan-amount"
                                    className="text-foreground text-right font-semibold tabular-nums"
                                >
                                    {formatMoney(
                                        subscription.amount,
                                        subscription.currency,
                                        locale,
                                    )}
                                    {subscription.interval && (
                                        <span className="text-muted-foreground block text-xs font-normal">
                                            {(subscription.interval_count ?? 1) > 1
                                                ? t('every :count :interval', {
                                                      count: String(
                                                          subscription.interval_count,
                                                      ),
                                                      interval: t(
                                                          `${subscription.interval}s`,
                                                      ),
                                                  })
                                                : `/ ${t(subscription.interval)}`}
                                        </span>
                                    )}
                                </p>
                            )}
                        </div>

                        <PlanFeatures features={subscription.features} />

                        <div className="border-border flex items-center justify-between gap-4 border-t pt-4">
                            <p className="text-muted-foreground text-sm">
                                {/* Trial, then trouble, then the ordinary dates */}
                                {subscription.suspended ? (
                                    <>
                                        <span
                                            className="text-destructive"
                                            data-testid="subscription-suspended"
                                        >
                                            {t(
                                                'Suspended — update your payment details to start it again',
                                            )}
                                        </span>
                                    </>
                                ) : subscription.grace_ends_at ? (
                                    <>
                                        <span
                                            className="text-destructive"
                                            data-testid="subscription-grace"
                                        >
                                            {t(
                                                'Payment failed — update your card by :date to keep this subscription',
                                                {
                                                    date: formatDate(
                                                        subscription.grace_ends_at,
                                                        locale,
                                                        longDate,
                                                    ),
                                                },
                                            )}
                                        </span>
                                    </>
                                ) : subscription.on_trial &&
                                  !subscription.cancelled_at ? (
                                    <>
                                        <span data-testid="subscription-trial">
                                            {t('Trial ends on :date', {
                                                date: formatDate(
                                                    subscription.trial_ends_at,
                                                    locale,
                                                    longDate,
                                                ),
                                            })}
                                        </span>
                                    </>
                                ) : subscription.cancelled_at ? (
                                    <>
                                        {subscription.replaced_by_lifetime ? (
                                            <span data-testid="replaced-by-lifetime">
                                                {t(
                                                    'Ends on :date, replaced by your lifetime plan',
                                                    {
                                                        date: formatDate(
                                                            subscription.ends_at,
                                                            locale,
                                                            longDate,
                                                        ),
                                                    },
                                                )}
                                            </span>
                                        ) : (
                                            <span
                                                className="text-destructive"
                                                data-testid="subscription-cancels-on"
                                            >
                                                {t('Cancels on :date', {
                                                    date: formatDate(
                                                        subscription.ends_at,
                                                        locale,
                                                        longDate,
                                                    ),
                                                })}
                                            </span>
                                        )}
                                    </>
                                ) : (
                                    subscription.current_period_ends_at && (
                                        <>
                                            {t('Renews on :date', {
                                                date: formatDate(
                                                    subscription.current_period_ends_at,
                                                    locale,
                                                    longDate,
                                                ),
                                            })}
                                        </>
                                    )
                                )}
                            </p>
                            {subscription.needs_payment_method ? (
                                <Button
                                    asChild
                                    size="sm"
                                    data-testid="add-payment-method"
                                >
                                    <a href={billingPortalUrl}>
                                        {t('Add payment method')}
                                    </a>
                                </Button>
                            ) : (
                                <Button asChild variant="outline" size="sm">
                                    <a href={billingPortalUrl}>
                                        {t('Adjust plan')}
                                    </a>
                                </Button>
                            )}
                        </div>
                    </Card>

                    {/* Payment method */}
                    <div className="flex items-start justify-between gap-4">
                        <div className="space-y-1">
                            <h3 className="font-medium">
                                {t('Payment Method')}
                            </h3>
                            <p className="text-muted-foreground text-sm">
                                {paymentMethod ? (
                                    <PaymentMethodLine
                                        paymentMethod={paymentMethod}
                                    />
                                ) : (
                                    t('No payment method on file')
                                )}
                            </p>
                        </div>
                        <Button asChild variant="outline" size="sm">
                            <a href={billingPortalUrl}>{t('Update')}</a>
                        </Button>
                    </div>

                    {/* Invoices */}
                    <div className="space-y-3">
                        <h3 className="font-medium">{t('Invoices')}</h3>

                        {invoices.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-border border-b">
                                            <th className="text-muted-foreground pb-2 text-left font-medium">
                                                {t('Date')}
                                            </th>
                                            <th className="text-muted-foreground pb-2 text-right font-medium">
                                                {t('Amount')}
                                            </th>
                                            <th className="text-muted-foreground pb-2 pl-6 text-left font-medium">
                                                {t('Status')}
                                            </th>
                                            <th className="text-muted-foreground pb-2 text-right font-medium">
                                                {t('Invoice')}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invoices.map((invoice) => (
                                            <tr
                                                key={invoice.id}
                                                className="border-border border-b last:border-0"
                                            >
                                                <td className="text-foreground py-3">
                                                    {formatDate(
                                                        invoice.paid_at,
                                                        locale,
                                                        longDate,
                                                    )}
                                                </td>
                                                <td className="text-foreground py-3 text-right tabular-nums">
                                                    {formatMoney(
                                                        invoice.total,
                                                        invoice.currency,
                                                        locale,
                                                    )}
                                                </td>
                                                <td className="py-3 pl-6">
                                                    <Badge
                                                        variant={statusVariant(
                                                            invoice.status,
                                                        )}
                                                    >
                                                        {t(
                                                            ucfirst(
                                                                invoice.status,
                                                            ),
                                                        )}
                                                    </Badge>
                                                </td>
                                                <td className="py-3 text-right">
                                                    {invoice.hosted_invoice_url ? (
                                                        <a
                                                            href={
                                                                invoice.hosted_invoice_url
                                                            }
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="text-primary/70 text-sm font-medium underline-offset-4 hover:underline"
                                                        >
                                                            {t('View')}
                                                        </a>
                                                    ) : (
                                                        <span className="text-foreground/50">
                                                            &mdash;
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                {t('No invoices yet')}
                            </p>
                        )}
                    </div>

                    {/* Resume, when the plan is already cancelled; a lifetime
                        plan replacing it leaves nothing to resume. */}
                    {subscription.replaced_by_lifetime ? null : subscription.cancelled_at ? (
                        <div className="space-y-3">
                            <div className="space-y-1">
                                <h3 className="font-medium">
                                    {t('Resume subscription')}
                                </h3>
                                <p className="text-muted-foreground text-sm">
                                    {t(
                                        'Changed your mind? Resume your subscription to keep your access.',
                                    )}
                                </p>
                            </div>
                            <Button
                                data-testid="resume-button"
                                size="sm"
                                disabled={isResuming}
                                onClick={resumeSubscription}
                            >
                                {isResuming && (
                                    <Loader2 className="mr-2 size-4 animate-spin" />
                                )}
                                {t('Resume plan')}
                            </Button>
                        </div>
                    ) : (
                        /* Otherwise, the way out */
                        <DangerZone showHeader={false}>
                            <div className="space-y-1">
                                <h3 className="font-medium">
                                    {t('Cancellation')}
                                </h3>
                                <p className="text-muted-foreground text-sm">
                                    {t(
                                        'Your subscription will remain active until the end of the current billing period.',
                                    )}
                                </p>
                            </div>
                            <Button
                                data-testid="cancel-button"
                                variant="destructive"
                                size="sm"
                                disabled={isCancelling}
                                onClick={handleCancelSubscription}
                            >
                                {isCancelling && (
                                    <Loader2 className="mr-2 size-4 animate-spin" />
                                )}
                                {t('Cancel subscription')}
                            </Button>
                        </DangerZone>
                    )}
                </div>
            ) : lifetimePlans.length > 0 ? null : (
                /* Nothing bought yet */
                <EmptyState
                    title={t('No active subscription')}
                    description={t(
                        'Choose a plan to get started with all the features.',
                    )}
                    icon={<CreditCard />}
                    data-testid="no-subscription"
                >
                    <Button asChild>
                        <a href={route('billing.plans')}>{t('View Plans')}</a>
                    </Button>
                </EmptyState>
            )}

            {/* One-time payments: a lifetime plan has no invoice to list */}
            {showPayments && (
                <div className="space-y-3" data-testid="payments">
                    <h3 className="font-medium">{t('Payments')}</h3>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-border border-b">
                                <th className="text-muted-foreground pb-2 text-left font-medium">
                                    {t('Date')}
                                </th>
                                <th className="text-muted-foreground pb-2 text-right font-medium">
                                    {t('Amount')}
                                </th>
                                <th className="text-muted-foreground pb-2 pl-6 text-left font-medium">
                                    {t('Status')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.map((payment) => (
                                <tr
                                    key={payment.id}
                                    data-testid={`payment-row-${payment.id}`}
                                    className="border-border border-b last:border-0"
                                >
                                    <td className="text-foreground py-3">
                                        {formatDate(
                                            payment.paid_at,
                                            locale,
                                            longDate,
                                        )}
                                    </td>
                                    <td className="text-foreground py-3 text-right tabular-nums">
                                        {formatMoney(
                                            payment.amount,
                                            payment.currency,
                                            locale,
                                        )}
                                    </td>
                                    <td className="py-3 pl-6">
                                        <Badge
                                            variant={
                                                payment.status === 'succeeded'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                        >
                                            {payment.status === 'succeeded'
                                                ? t('Paid')
                                                : t('Refunded')}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
