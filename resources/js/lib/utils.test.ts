import { describe, expect, it } from 'vitest';
import { formatCurrency, parseAmount } from './utils';

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

describe('parseAmount', () => {
    it('reads Italian input with thousands dots and decimal comma', () => {
        expect(parseAmount('1.234,50')).toBe(1234.5);
        expect(parseAmount('1.200')).toBe(1200);
        expect(parseAmount('60,50')).toBe(60.5);
        expect(parseAmount('-30')).toBe(-30);
    });

    it('keeps reading server decimals with a dot', () => {
        expect(parseAmount('1200.50')).toBe(1200.5);
        expect(parseAmount('12.5')).toBe(12.5);
        expect(parseAmount('')).toBe(0);
    });
});
