import { describe, expect, it } from 'vitest';
import type { Price, Promotion } from '../types';
import { promotionNotes } from './promotion';

const promotion = (overrides: Partial<Promotion> = {}): Promotion => ({
    code: 'LAUNCH',
    percent_off: null,
    amount_off: 3000,
    currency: 'USD',
    duration: 'once',
    duration_in_months: null,
    max_redemptions: 50,
    remaining: 38,
    expires_at: null,
    ...overrides,
});

const price = (
    p: Promotion | null,
    interval: string | null = 'year',
): Price => ({
    id: 1,
    amount: 17900,
    currency: 'USD',
    interval,
    promotion: p,
    promotional_amount: p ? 14900 : null,
});

describe('promotionNotes', () => {
    it('says nothing without a promotion', () => {
        expect(promotionNotes(price(null), 'en')).toEqual([]);
    });

    it('counts what is left of a capped promotion', () => {
        expect(promotionNotes(price(promotion()), 'en')).toContainEqual([
            ':left of :max left',
            { left: '38', max: '50' },
        ]);
    });

    it('names the end date', () => {
        const [, params] = promotionNotes(
            price(
                promotion({
                    max_redemptions: null,
                    remaining: null,
                    expires_at: '2026-12-31T12:00:00Z',
                }),
            ),
            'en',
        ).find(([key]) => key === 'Until :date')!;

        expect(params.date).toContain('2026');
    });

    it('says a subscription discount covers the first payment only', () => {
        expect(promotionNotes(price(promotion()), 'en')).toContainEqual([
            'First payment, then :price',
            { price: '$179' },
        ]);
    });

    it('says how many months a repeating discount lasts', () => {
        expect(
            promotionNotes(
                price(
                    promotion({ duration: 'repeating', duration_in_months: 3 }),
                    'month',
                ),
                'en',
            ),
        ).toContainEqual([
            'For :months months, then :price',
            { months: '3', price: '$179' },
        ]);
    });

    it('says nothing about duration for a one-time price or a forever discount', () => {
        const keys = (p: Price) => promotionNotes(p, 'en').map(([key]) => key);

        expect(keys(price(promotion(), null))).toEqual([':left of :max left']);
        expect(keys(price(promotion({ duration: 'forever' })))).toEqual([
            ':left of :max left',
        ]);
    });
});
