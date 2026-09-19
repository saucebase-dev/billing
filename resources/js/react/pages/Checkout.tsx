import { Button } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useT } from '@/i18n';
import { useForm, usePage } from '@inertiajs/react';
import type { CheckoutSession } from '@modules/billing/resources/js/types';
import { useState, type FormEvent } from 'react';
import { getIntervalDisplay } from '../../utils/intervals';
import CheckoutLayout from '../layouts/CheckoutLayout';

import IconCheck from '~icons/heroicons/check';
import IconLock from '~icons/heroicons/lock-closed';

function formatPrice(amount: number, currency?: string): string {
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency?.toUpperCase() ?? 'EUR',
    }).format(amount / 100);
}

export default function Checkout({ session }: { session: CheckoutSession }) {
    const t = useT();
    const page = usePage();
    const user = page.props.auth?.user;

    const price = session.price;
    const product = price.product;

    const { data, setData, post, processing } = useForm({
        email: user?.email ?? '',
        coupon: '',
    });

    const [showCoupon, setShowCoupon] = useState(false);

    function handleCheckout(event: FormEvent) {
        event.preventDefault();
        post(route('billing.checkout.store', session.uuid));
    }

    const summary = (
        <div data-testid="order-summary">
            <p className="text-foreground/70 text-sm">
                {t('Subscribe to :plan', { plan: product.name })}
            </p>

            <div className="mt-2 flex items-baseline gap-2">
                <span
                    data-testid="checkout-total"
                    className="text-foreground text-4xl font-semibold tracking-tight"
                >
                    {formatPrice(price.amount, price.currency)}
                </span>
                <span className="text-foreground/70 text-sm">
                    {getIntervalDisplay(price.interval)}
                </span>
            </div>

            {/* Line item */}
            <div className="border-border mt-8 border-t pt-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h2
                            data-testid="checkout-product-name"
                            className="text-foreground font-medium"
                        >
                            {product.name}
                        </h2>
                        {product.description && (
                            <p
                                className="text-foreground/70 mt-1 text-sm"
                                dangerouslySetInnerHTML={{
                                    __html: product.description,
                                }}
                            />
                        )}
                    </div>
                    <span className="text-foreground shrink-0 text-sm">
                        {formatPrice(price.amount, price.currency)}
                    </span>
                </div>

                {!!product.features?.length && (
                    <ul className="mt-4 space-y-2">
                        {product.features.map((feature, index) => (
                            <li
                                key={index}
                                className="text-foreground/70 flex items-center gap-2 text-sm"
                            >
                                <IconCheck className="text-primary size-4 shrink-0" />
                                {feature}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {/* Totals */}
            <div className="border-border mt-6 space-y-2 border-t pt-6">
                <div className="text-foreground/70 flex items-center justify-between text-sm">
                    <span>{t('Subtotal')}</span>
                    <span>{formatPrice(price.amount, price.currency)}</span>
                </div>
                <p className="text-foreground/50 text-xs">
                    {t('Tax will be calculated at payment if applicable')}
                </p>

                {/* Hidden until asked for, so it does not send anyone off
                    hunting for a code they do not have. */}
                {!showCoupon ? (
                    <button
                        type="button"
                        data-testid="checkout-coupon-toggle"
                        className="bg-foreground/5 text-foreground hover:bg-foreground/10 mt-2 rounded-lg px-3 py-2 text-sm font-medium transition"
                        onClick={() => setShowCoupon(true)}
                    >
                        {t('Add promotion code')}
                    </button>
                ) : (
                    <Field>
                        <FieldLabel htmlFor="coupon">
                            {t('Promotion code')}
                        </FieldLabel>
                        <Input
                            id="coupon"
                            name="coupon"
                            data-testid="checkout-coupon"
                            type="text"
                            placeholder={t('Enter your code')}
                            value={data.coupon}
                            onChange={(event) =>
                                setData('coupon', event.target.value)
                            }
                        />
                    </Field>
                )}
            </div>

            <div className="border-border mt-4 flex items-center justify-between border-t pt-4">
                <span className="text-foreground font-semibold">
                    {t('Total due today')}
                </span>
                <span className="text-foreground text-lg font-bold">
                    {formatPrice(price.amount, price.currency)}
                </span>
            </div>

            {!price.interval && (
                <p className="text-foreground/50 mt-3 text-xs">
                    {t(
                        'This is a one-time payment. You will not be charged again.',
                    )}
                </p>
            )}
        </div>
    );

    return (
        <CheckoutLayout
            title={t('Checkout')}
            backHref={route('billing.plans')}
            summary={summary}
        >
            {/* Right: what we need from you */}
            <h2 className="text-foreground text-lg font-semibold">
                {t('Billing information')}
            </h2>

            <form
                data-testid="checkout-form"
                onSubmit={handleCheckout}
                className="mt-6 space-y-4"
            >
                <Field>
                    <FieldLabel htmlFor="email">{t('Email')}</FieldLabel>
                    <Input
                        id="email"
                        name="email"
                        data-testid="checkout-email"
                        type="email"
                        placeholder={t('Enter your email')}
                        autoComplete="email"
                        required
                        value={data.email}
                        onChange={(event) =>
                            setData('email', event.target.value)
                        }
                    />
                </Field>

                <p className="text-foreground/50 text-xs">
                    {t(
                        'Your billing address is collected on the payment page when it is needed.',
                    )}
                </p>

                <Button
                    type="submit"
                    data-testid="checkout-submit"
                    className="mt-6 w-full text-base"
                    disabled={processing}
                >
                    {processing ? t('Redirecting...') : t('Proceed to Payment')}
                </Button>

                <div className="text-foreground/50 flex items-center justify-center gap-1.5 text-xs">
                    <IconLock className="size-3.5" />
                    {t('Payments are secure and encrypted')}
                </div>

                {/* Guarded: both routes belong to the host app, and a site without
                    them would otherwise take the whole checkout page down. */}
                {route().has('terms') && route().has('privacy') && (
                    <p className="text-foreground/50 text-center text-xs">
                        {t('By continuing you agree to our')}{' '}
                        <a
                            href={route('terms')}
                            className="hover:text-foreground underline underline-offset-4"
                        >
                            {t('Terms of Service')}
                        </a>{' '}
                        {t('and')}{' '}
                        <a
                            href={route('privacy')}
                            className="hover:text-foreground underline underline-offset-4"
                        >
                            {t('Privacy Policy')}
                        </a>
                    </p>
                )}
            </form>
        </CheckoutLayout>
    );
}
