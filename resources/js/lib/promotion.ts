import type { Price } from '../types';
import { formatMoney } from './money';

/** A translation key and its replacements; each card translates with its own `t`. */
export type Note = [string, Record<string, string>];

/**
 * The lines under a promoted price: how long the discount lasts on a
 * subscription, how many are left, and until when. Empty without a promotion.
 */
export function promotionNotes(
    price: Price,
    locale: string | undefined,
): Note[] {
    const promotion = price.promotion;

    if (!promotion || price.promotional_amount == null) return [];

    const notes: Note[] = [];
    const regular = formatMoney(price.amount, price.currency, locale, {
        wholeUnits: true,
    });

    // A one-time price is paid once, and a forever discount never ends.
    if (price.interval && promotion.duration === 'once') {
        notes.push(['First payment, then :price', { price: regular }]);
    } else if (price.interval && promotion.duration === 'repeating') {
        notes.push([
            'For :months months, then :price',
            {
                months: String(promotion.duration_in_months ?? 1),
                price: regular,
            },
        ]);
    }

    if (promotion.remaining != null && promotion.max_redemptions != null) {
        notes.push([
            ':left of :max left',
            {
                left: String(promotion.remaining),
                max: String(promotion.max_redemptions),
            },
        ]);
    }

    if (promotion.expires_at) {
        notes.push([
            'Until :date',
            {
                date: new Intl.DateTimeFormat(locale, {
                    dateStyle: 'medium',
                }).format(new Date(promotion.expires_at)),
            },
        ]);
    }

    return notes;
}
