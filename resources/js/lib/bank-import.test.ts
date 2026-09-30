import { describe, expect, it } from 'vitest';
import { categoriesFor, groupByMerchant, type ImportCategory, type ImportTransaction } from './bank-import';

function transaction(overrides: Partial<ImportTransaction>): ImportTransaction {
    return {
        id: 1,
        kind: 'card',
        kind_label: 'Carta',
        accounting_date: '2026-01-01',
        operation_date: '2026-01-01',
        amount: -10,
        merchant_key: 'IPER',
        merchant_label: 'IPER MAGENTA',
        raw_description: '',
        payment_instrument: null,
        bank_category: null,
        category_id: null,
        source: null,
        source_label: null,
        confidence: null,
        status: 'to_review',
        ...overrides,
    };
}

describe('groupByMerchant', () => {
    it('groups by merchant with totals and shared category', () => {
        const groups = groupByMerchant([
            transaction({ id: 1, merchant_key: 'IPER', amount: -10, category_id: 3 }),
            transaction({ id: 2, merchant_key: 'LIDL', amount: -5 }),
            transaction({ id: 3, merchant_key: 'IPER', amount: -20.5, category_id: 3 }),
        ]);

        expect(groups.map((g) => g.merchantKey)).toEqual(['IPER', 'LIDL']);
        expect(groups[0].total).toBeCloseTo(-30.5);
        expect(groups[0].categoryId).toBe(3);
        expect(groups[0].mixed).toBe(false);
    });

    it('flags mixed categories', () => {
        const [group] = groupByMerchant([
            transaction({ id: 1, category_id: 3 }),
            transaction({ id: 2, category_id: 4 }),
        ]);

        expect(group.mixed).toBe(true);
        expect(group.categoryId).toBeNull();
    });
});

describe('categoriesFor', () => {
    const categories: ImportCategory[] = [
        { id: 1, name: 'Stipendio', type: 'income', color: null },
        { id: 2, name: 'Spesa', type: 'expense', color: null },
    ];

    it('offers only expense categories for money going out', () => {
        expect(categoriesFor(-10, categories).map((c) => c.id)).toEqual([2]);
    });

    it('offers every category for money coming in', () => {
        expect(categoriesFor(10, categories).map((c) => c.id)).toEqual([1, 2]);
    });
});
