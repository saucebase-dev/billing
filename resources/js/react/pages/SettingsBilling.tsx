import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDialog } from '@/hooks/useDialog';
import { useT } from '@/i18n';
import { router } from '@inertiajs/react';
import { CreditCard, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import type { Invoice, PaymentMethod, Subscription } from '../../types';

function formatDate(date: string | null): string {
    if (!date) return '';

    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

function formatCurrency(amount: number, currency: string | null): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency?.toUpperCase() ?? 'USD',
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
                        &middot; {t('Expires')} {pad(details.expMonth)}/
                        {details.expYear}
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
    paymentMethod,
    invoices,
    billingPortalUrl,
}: {
    subscription: Subscription | null;
    paymentMethod: PaymentMethod | null;
    invoices: Invoice[];
    billingPortalUrl: string;
}) {
    const t = useT();
    const { confirm } = useDialog();
    const [isCancelling, setIsCancelling] = useState(false);
    const [isResuming, setIsResuming] = useState(false);

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
                {t('Manage your subscription, payment method, and invoices')}
            </p>

            {subscription ? (
                <div data-testid="subscription-section" className="space-y-8">
                    {/* Current plan */}
                    <div className="flex items-start justify-between gap-4">
                        <div className="space-y-1">
                            <h3 className="font-medium">{t('Current Plan')}</h3>
                            <p
                                data-testid="plan-name"
                                className="text-foreground text-lg font-semibold"
                            >
                                {subscription.plan_name ?? t('Unknown Plan')}
                            </p>
                            <p className="text-muted-foreground text-sm">
                                {formatInterval(subscription.interval)}
                                {subscription.cancelled_at ? (
                                    <>
                                        {' '}
                                        &middot;{' '}
                                        <span className="text-destructive">
                                            {t('Cancels on')}{' '}
                                            {formatDate(subscription.ends_at)}
                                        </span>
                                    </>
                                ) : (
                                    subscription.current_period_ends_at && (
                                        <>
                                            {' '}
                                            &middot; {t('Renews on')}{' '}
                                            {formatDate(
                                                subscription.current_period_ends_at,
                                            )}
                                        </>
                                    )
                                )}
                            </p>
                        </div>
                        <a href={billingPortalUrl}>
                            <Button variant="outline" size="sm">
                                {t('Adjust plan')}
                            </Button>
                        </a>
                    </div>

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
                        <a href={billingPortalUrl}>
                            <Button variant="outline" size="sm">
                                {t('Update')}
                            </Button>
                        </a>
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
                                            <th className="text-muted-foreground pb-2 text-left font-medium">
                                                {t('Amount')}
                                            </th>
                                            <th className="text-muted-foreground pb-2 text-left font-medium">
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
                                                    )}
                                                </td>
                                                <td className="text-foreground py-3">
                                                    {formatCurrency(
                                                        invoice.total,
                                                        invoice.currency,
                                                    )}
                                                </td>
                                                <td className="py-3">
                                                    <Badge
                                                        variant={statusVariant(
                                                            invoice.status,
                                                        )}
                                                    >
                                                        {invoice.status}
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

                    {/* Resume, when the plan is already cancelled */}
                    {subscription.cancelled_at ? (
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
                        <div className="space-y-3">
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
                        </div>
                    )}
                </div>
            ) : (
                /* Nothing bought yet */
                <div
                    data-testid="no-subscription"
                    className="border-border flex w-full flex-col items-center justify-center rounded-lg border border-dashed p-12 text-center"
                >
                    <h3 className="text-foreground font-medium">
                        {t('No active subscription')}
                    </h3>
                    <p className="text-muted-foreground mt-2 text-sm">
                        {t(
                            'Choose a plan to get started with all the features.',
                        )}
                    </p>
                    <a href={route('billing.plans')} className="mt-4">
                        <Button>{t('View Plans')}</Button>
                    </a>
                </div>
            )}
        </div>
    );
}
