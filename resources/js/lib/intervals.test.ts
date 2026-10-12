import { describe, expect, it } from 'vitest';
import {
    getIntervalDisplay,
    getIntervalLabel,
    matchesInterval,
    normalizeInterval,
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
