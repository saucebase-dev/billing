import { describe, expect, it } from 'vitest';
import { formatMoney } from './money';

describe('formatMoney', () => {
    it('formats minor units in the given language', () => {
        expect(formatMoney(1999, 'eur', 'en')).toBe('€19.99');
        expect(formatMoney(1999, 'EUR', 'de')).toBe('19,99\u00a0€');
    });

    it('falls back to euros when the currency is missing', () => {
        expect(formatMoney(500, null, 'en')).toBe('€5.00');
    });

    it('drops the cents when asked', () => {
        expect(formatMoney(1900, 'usd', 'en', { wholeUnits: true })).toBe(
            '$19',
        );
    });
});
