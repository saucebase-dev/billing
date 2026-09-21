import { useT } from '@/i18n';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import type { Price, Product } from '@modules/billing/resources/js/types';
import { getIntervalDisplay } from '../../lib/intervals';

function formatPrice(amount: number | string, currency?: string): string {
    const cents = typeof amount === 'string' ? parseFloat(amount) : amount;
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency?.toUpperCase() ?? 'EUR',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(cents / 100);
}

export default function ProductCard({
    product,
    price,
    isCurrent = false,
}: {
    product: Product;
    price?: Price;
    isCurrent?: boolean;
}) {
    const t = useT();
    // A free plan never reaches the provider; a paid one it does not know yet
    // would be refused at checkout.
    const unavailable = !!price && price.amount > 0 && !price.provider_price_id;
    const featured = !!product.metadata?.badge || product.is_highlighted;
    const [isAnimating, setIsAnimating] = useState(false);

    // The amount, not the price object: switching interval swaps the object on
    // every render, and only a changed figure is worth drawing attention to.
    useEffect(() => {
        setIsAnimating(true);
        const timer = setTimeout(() => setIsAnimating(false), 150);

        return () => clearTimeout(timer);
    }, [price?.amount]);

    const ctaClasses = featured
        ? 'bg-primary hover:bg-primary/90 focus-visible:outline-primary text-white'
        : 'text-foreground ring-border hover:bg-foreground/10 ring-1 ring-inset';

    function handleGetStarted() {
        if (!price) return;

        if (price.amount === 0) {
            router.visit(route('register'));
            return;
        }

        router.post(route('billing.checkout.create'), {
            price_id: price.id,
        });
    }

    return (
        <div
            data-testid={`product-card-${product.slug}`}
            className={`relative flex h-full flex-col rounded-3xl p-8 shadow-lg ${
                featured
                    ? 'ring-primary bg-card/70 shadow-lg ring-3 lg:scale-[1.05]'
                    : 'bg-card/70 ring-border ring-1'
            }`}
        >
            {featured && (
                <span
                    data-testid="product-badge"
                    className="bg-primary absolute left-1/2 -translate-x-1/2 -translate-y-2/1 rounded-md px-3 py-1 text-xs font-semibold text-white shadow-2xl"
                >
                    {product.metadata?.badge || t('Most popular')}
                </span>
            )}

            {/* Plan Name with Badge */}
            <div className="flex items-center justify-between gap-x-4">
                <h3
                    className={`text-2xl font-semibold ${
                        featured
                            ? 'text-secondary dark:text-secondary-light'
                            : 'text-foreground'
                    }`}
                >
                    {product.name}
                </h3>
            </div>

            {/* Description */}
            {product.description && (
                <p
                    className="text-foreground/70 mt-2 text-sm"
                    dangerouslySetInnerHTML={{ __html: product.description }}
                />
            )}

            {/* Price */}
            <div className="mt-2">
                {/* Original price + discount badge */}
                {price?.metadata?.original_price || price?.metadata?.badge ? (
                    <div className="mb-1 flex h-8 items-center gap-2">
                        {price.metadata?.original_price && (
                            <span className="text-foreground/50 text-2xl line-through">
                                {formatPrice(
                                    price.metadata.original_price,
                                    price.currency,
                                )}
                            </span>
                        )}
                        {price.metadata?.badge && (
                            <span className="text-sm font-medium text-green-600 dark:text-green-400">
                                {price.metadata.badge}
                            </span>
                        )}
                    </div>
                ) : (
                    <div className="h-9" />
                )}

                {/* Current price */}
                <div
                    className={`flex items-baseline gap-x-1 transition-transform duration-150 ${
                        isAnimating ? 'scale-105' : ''
                    }`}
                >
                    {price ? (
                        <>
                            <span className="text-foreground text-5xl font-semibold tracking-tight">
                                {formatPrice(price.amount, price.currency)}
                            </span>
                            <span className="text-foreground/70 text-base">
                                {getIntervalDisplay(price.interval)}
                            </span>
                        </>
                    ) : (
                        <span className="text-foreground text-2xl font-semibold">
                            {t('Contact us')}
                        </span>
                    )}
                </div>
            </div>

            {/* Tagline from metadata */}
            {product.metadata?.tagline && (
                <p className="text-foreground/70 mt-2 text-sm italic">
                    {product.metadata.tagline}
                </p>
            )}

            {/* CTA Button */}
            {product.metadata?.cta_url ? (
                <a
                    href={product.metadata.cta_url}
                    className={`mt-8 block w-full cursor-pointer rounded-xl px-4 py-3 text-center font-semibold shadow-2xl transition-all duration-200 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 ${ctaClasses}`}
                >
                    {product.metadata?.cta_label || t('Get started')}
                </a>
            ) : (
                <button
                    data-testid="get-started-button"
                    className={`mt-8 w-full cursor-pointer rounded-xl px-4 py-3 font-semibold shadow-lg transition-all duration-200 focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-50 ${ctaClasses}`}
                    disabled={isCurrent || unavailable}
                    onClick={handleGetStarted}
                >
                    {isCurrent
                        ? t('Current plan')
                        : unavailable
                          ? t('Not available')
                          : product.metadata?.cta_label || t('Get started')}
                </button>
            )}

            {/* After CTA text from metadata */}
            {product.metadata?.after_cta && (
                <div className="text-foreground/70 mt-2 text-center text-sm">
                    {t(product.metadata.after_cta)}
                </div>
            )}

            {/* Features */}
            {!!product.features?.length && (
                <ul className="text-foreground/70 mt-6 flex-1 space-y-1 text-sm">
                    {product.features.map((feature, index) => (
                        <li key={index} className="flex items-start gap-3">
                            <svg
                                className="text-primary mt-0.5 h-5 w-5 shrink-0"
                                fill="currentColor"
                                viewBox="0 0 20 20"
                            >
                                <path
                                    fillRule="evenodd"
                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                    clipRule="evenodd"
                                />
                            </svg>
                            <span>{feature}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
