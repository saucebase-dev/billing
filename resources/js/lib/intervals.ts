export const INTERVAL_CONFIG: Record<
    string,
    { display: string; label: string; normalized: string }
> = {
    day: { display: '/day', label: 'Daily', normalized: 'day' },
    daily: { display: '/day', label: 'Daily', normalized: 'day' },
    week: { display: '/week', label: 'Weekly', normalized: 'week' },
    weekly: { display: '/week', label: 'Weekly', normalized: 'week' },
    month: { display: '/month', label: 'Monthly', normalized: 'month' },
    monthly: { display: '/month', label: 'Monthly', normalized: 'month' },
    year: { display: '/year', label: 'Yearly', normalized: 'year' },
    yearly: { display: '/year', label: 'Yearly', normalized: 'year' },
};

export function getIntervalDisplay(interval: string | null): string {
    if (!interval) return 'one-time';
    return INTERVAL_CONFIG[interval]?.display || `/${interval}`;
}

export function normalizeInterval(interval: string | null): string {
    if (!interval) return 'one_time';
    return INTERVAL_CONFIG[interval]?.normalized || interval;
}

export function matchesInterval(
    priceInterval: string | null,
    selectedInterval: string,
): boolean {
    return normalizeInterval(priceInterval) === selectedInterval;
}

export function getIntervalLabel(interval: string): string {
    return INTERVAL_CONFIG[interval]?.label || interval;
}

/**
 * The toggle's interval: the current one while it is offered, else the first offered.
 * Decided while rendering (not in an effect), so the server's first paint agrees.
 */
export function pickInterval(available: string[], current: string): string {
    return available.length === 0 || available.includes(current)
        ? current
        : available[0];
}

// Sort order: one_time, day, week, month, year
const ORDER = ['one_time', 'day', 'week', 'month', 'year'];

/**
 * The intervals the toggle offers. None unless some plan is priced in more than one:
 * the toggle switches the same plans between, say, monthly and yearly. With one price
 * per plan it would only hide plans from each other, so they all show together.
 */
export function toggleIntervals(
    products: { prices: { interval: string | null }[] }[],
): string[] {
    const perPlan = products.map(
        (product) =>
            new Set(
                product.prices.map((price) =>
                    normalizeInterval(price.interval),
                ),
            ),
    );

    if (!perPlan.some((intervals) => intervals.size > 1)) {
        return [];
    }

    return Array.from(
        new Set(perPlan.flatMap((intervals) => [...intervals])),
    ).sort((a, b) => ORDER.indexOf(a) - ORDER.indexOf(b));
}
