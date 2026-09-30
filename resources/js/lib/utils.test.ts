import { describe, expect, it } from 'vitest';
import { formatCurrency } from './utils';

describe('formatCurrency', () => {
    it('uses the dot as thousands separator for four-digit amounts', () => {
        expect(formatCurrency(1234.5)).toBe('1.234,50 €');
    });

    it('uses the dot as thousands separator for larger amounts', () => {
        expect(formatCurrency(-12345.67)).toBe('-12.345,67 €');
    });

    it('leaves amounts below a thousand ungrouped', () => {
        expect(formatCurrency(999)).toBe('999,00 €');
    });
});
