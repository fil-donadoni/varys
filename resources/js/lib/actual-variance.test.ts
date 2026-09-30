import { describe, expect, it } from 'vitest';
import { formatPercent, varianceRatio, varianceTone } from './actual-variance';

describe('varianceTone', () => {
    it('classifies expenses by how much they exceed the budget', () => {
        expect(varianceTone(250, 300, 'expense')).toBe('good');
        expect(varianceTone(270, 300, 'expense')).toBe('ok');
        expect(varianceTone(330, 300, 'expense')).toBe('ok');
        expect(varianceTone(331, 300, 'expense')).toBe('warn');
        expect(varianceTone(390, 300, 'expense')).toBe('warn');
        expect(varianceTone(420, 300, 'expense')).toBe('bad');
    });

    it('inverts the logic for income', () => {
        expect(varianceTone(2400, 2000, 'income')).toBe('good');
        expect(varianceTone(1500, 2000, 'income')).toBe('warn');
        expect(varianceTone(1000, 2000, 'income')).toBe('bad');
    });

    it('handles missing budget', () => {
        expect(varianceTone(0, 0, 'expense')).toBe('none');
        expect(varianceTone(120, 0, 'expense')).toBe('unbudgeted');
        expect(varianceTone(-20, 0, 'expense')).toBe('good');
        expect(varianceTone(500, 0, 'income')).toBe('good');
    });
});

describe('varianceRatio / formatPercent', () => {
    it('returns the relative difference', () => {
        expect(varianceRatio(420, 300)).toBeCloseTo(0.4);
        expect(varianceRatio(10, 0)).toBeNull();
        expect(formatPercent(0.4)).toBe('+40%');
        expect(formatPercent(-0.0612)).toBe('-6%');
        expect(formatPercent(null)).toBe('—');
    });
});

describe('negative budgets (net balance)', () => {
    it('treats a deeper deficit than planned as worse, not better', () => {
        expect(varianceRatio(-1000, -500)).toBe(-1);
        expect(varianceTone(-1000, -500, 'income')).toBe('bad');
        expect(varianceTone(-300, -500, 'income')).toBe('good');
    });
});
