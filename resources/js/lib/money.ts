/**
 * An amount in minor units (cents), in the app's language.
 *
 * Pass the language rather than leaving it to the browser: pages render on the
 * server first, and a locale the two disagree on prints one price in the HTML and
 * another once the page hydrates.
 */
export function formatMoney(
    amount: number | string,
    currency: string | null | undefined,
    locale: string | undefined,
    { wholeUnits = false }: { wholeUnits?: boolean } = {},
): string {
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currency?.toUpperCase() || 'EUR',
        ...(wholeUnits
            ? { minimumFractionDigits: 0, maximumFractionDigits: 0 }
            : {}),
    }).format(Number(amount) / 100);
}
