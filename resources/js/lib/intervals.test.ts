import { describe, expect, it } from 'vitest';
import {
    getIntervalDisplay,
    getIntervalLabel,
    matchesInterval,
    normalizeInterval,
    pickInterval,
    toggleIntervals,
} from './intervals';

describe('intervals', () => {
    it('treats `monthly` and `month` as the same interval', () => {
        expect(normalizeInterval('monthly')).toBe('month');
        expect(normalizeInterval('month')).toBe('month');
        expect(normalizeInterval('yearly')).toBe('year');
    });

    it('matches a price to the selected interval through either spelling', () => {
        expect(matchesInterval('monthly', 'month')).toBe(true);
        expect(matchesInterval('year', 'year')).toBe(true);
        expect(matchesInterval('monthly', 'year')).toBe(false);
    });

    it('handles a one-time price, which has no interval', () => {
        expect(normalizeInterval(null)).toBe('one_time');
        expect(getIntervalDisplay(null)).toBe('one-time');
        expect(matchesInterval(null, 'one_time')).toBe(true);
        expect(matchesInterval(null, 'month')).toBe(false);
    });

    it('shows the suffix and the label', () => {
        expect(getIntervalDisplay('yearly')).toBe('/year');
        expect(getIntervalLabel('week')).toBe('Weekly');
    });

    it('passes an interval it does not know through', () => {
        expect(normalizeInterval('quarter')).toBe('quarter');
        expect(getIntervalDisplay('quarter')).toBe('/quarter');
        expect(getIntervalLabel('quarter')).toBe('quarter');
    });
});

describe('pickInterval', () => {
    it('keeps the current interval while it is offered', () => {
        expect(pickInterval(['month', 'year'], 'year')).toBe('year');
    });

    /** Decided while rendering, so the server's first paint selects a toggle that has cards. */
    it('falls back to the first offered interval', () => {
        expect(pickInterval(['year', 'one_time'], 'month')).toBe('year');
    });

    it('keeps the current one when nothing is offered', () => {
        expect(pickInterval([], 'month')).toBe('month');
    });
    it('offers a toggle only when a plan is priced in more than one interval', () => {
        const plan = (...intervals: (string | null)[]) => ({
            prices: intervals.map((interval) => ({ interval })),
        });

        // Monthly or yearly for the same plans, plus a lifetime: the toggle switches them.
        expect(toggleIntervals([plan('year', 'monthly'), plan(null)])).toEqual([
            'one_time',
            'month',
            'year',
        ]);

        // One price each (a subscription, or the same thing paid once): all fit on one screen.
        expect(toggleIntervals([plan('year'), plan(null)])).toEqual([]);
        expect(toggleIntervals([plan('month'), plan('month'), plan()])).toEqual(
            [],
        );
        expect(toggleIntervals([])).toEqual([]);
    });
});
