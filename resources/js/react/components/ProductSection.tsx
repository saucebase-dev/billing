import { useT } from '@/i18n';
import { useEffect, useMemo, useState, type ReactNode } from 'react';

import type { Product } from '@modules/billing/resources/js/types';
import {
    getIntervalLabel,
    matchesInterval,
    normalizeInterval,
} from '../../lib/intervals';

import ProductCard from './ProductCard';

// Sort order: one_time, day, week, month, year
const ORDER = ['one_time', 'day', 'week', 'month', 'year'];

function getToggleLabel(interval: string): string {
    if (interval === 'one_time') return 'One-time';
    return getIntervalLabel(interval);
}

export default function ProductSection({
    products,
    className,
    children,
    footer,
}: {
    products: Product[];
    className?: string;
    children?: ReactNode;
    footer?: ReactNode;
}) {
    const t = useT();

    const availableIntervals = useMemo(() => {
        const intervals = new Set<string>();

        for (const product of products ?? []) {
            for (const price of product.prices) {
                intervals.add(normalizeInterval(price.interval));
            }
        }

        return Array.from(intervals).sort(
            (a, b) => ORDER.indexOf(a) - ORDER.indexOf(b),
        );
    }, [products]);

    const [billingInterval, setBillingInterval] = useState('month');

    // Set default to first available interval when products change
    useEffect(() => {
        if (
            availableIntervals.length > 0 &&
            !availableIntervals.includes(billingInterval)
        ) {
            setBillingInterval(availableIntervals[0]);
        }
    }, [availableIntervals, billingInterval]);

    const filteredProducts = useMemo(
        () =>
            (products ?? [])
                .map((product) => ({
                    ...product,
                    prices: product.prices.filter((price) =>
                        matchesInterval(price.interval, billingInterval),
                    ),
                }))
                .filter((product) => product.prices.length > 0),
        [products, billingInterval],
    );

    const columns =
        filteredProducts.length === 2
            ? 'max-w-6xl lg:grid-cols-2'
            : filteredProducts.length === 3
              ? 'max-w-6xl lg:grid-cols-3'
              : filteredProducts.length >= 4
                ? 'max-w-6xl lg:grid-cols-4'
                : 'max-w-[450px]';

    return (
        <section
            id="pricing"
            className={`relative w-full overflow-hidden px-6 py-32 ${className ?? ''}`}
        >
            {children}

            {/* Billing Toggle */}
            {availableIntervals.length > 1 && (
                <div className="mt-16 flex justify-center">
                    <div className="bg-card/35 relative flex items-center rounded-xl p-1 shadow-lg">
                        {availableIntervals.map((interval) => (
                            <button
                                key={interval}
                                onClick={() => setBillingInterval(interval)}
                                className={`relative z-10 rounded-xl px-6 py-2 text-sm font-medium transition-all duration-200 ${
                                    billingInterval === interval
                                        ? 'bg-primary text-white'
                                        : 'text-foreground/70 hover:text-foreground'
                                }`}
                            >
                                {t(getToggleLabel(interval))}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* No plan can be bought until it exists at the provider, so a fresh
                install and a catalogue that was never pushed look the same here. */}
            {filteredProducts.length === 0 ? (
                <div
                    className="bg-muted/70 text-muted-foreground mx-auto mt-16 max-w-xl rounded-lg p-4 text-center text-xl"
                    data-testid="pricing-empty"
                >
                    {t(
                        'No plans are available right now. Please check back soon.',
                    )}
                </div>
            ) : (
                /* Pricing Cards */
                <div
                    className={`mx-auto mt-16 grid grid-cols-1 gap-8 ${columns}`}
                >
                    {filteredProducts.map((product) => (
                        <ProductCard
                            key={product.id}
                            product={product}
                            price={product.prices[0]}
                        />
                    ))}
                </div>
            )}

            {footer}
        </section>
    );
}
