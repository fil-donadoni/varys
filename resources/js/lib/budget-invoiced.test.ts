import { describe, expect, it } from 'vitest';
import { applyInvoicedFilter, computeInvoicedBudgetTotal } from './budget-invoiced';

const income = { id: 1, type: 'income' as const, is_invoiced: true };
const plainIncome = { id: 2, type: 'income' as const, is_invoiced: false };
const expense = { id: 3, type: 'expense' as const, is_invoiced: true };

describe('computeInvoicedBudgetTotal', () => {
    it('counts whole entries by their own flag, split entries by their item flags', () => {
        const entries = {
            1: {
                1: { is_invoiced: true, items: [] },
                2: { is_invoiced: false, items: [] },
                3: {
                    is_invoiced: true,
                    items: [
                        { amount: '300', is_invoiced: true },
                        { amount: '200', is_invoiced: false },
                    ],
                },
            },
        };
        const cells = { '1-1': '1000', '1-2': '500', '1-3': '500' };

        expect(computeInvoicedBudgetTotal([income], entries, cells)).toBe(1300);
    });

    it('uses the category default for cells typed but not yet saved', () => {
        const cells = { '1-1': '1.000,50', '2-1': '700', '3-1': '900' };

        expect(computeInvoicedBudgetTotal([income, plainIncome, expense], {}, cells)).toBe(1000.5);
    });

    it('ignores expense categories even when their entries are flagged', () => {
        const entries = { 3: { 1: { is_invoiced: true, items: [] } } };

        expect(computeInvoicedBudgetTotal([expense], entries, { '3-1': '900' })).toBe(0);
    });
});

describe('applyInvoicedFilter', () => {
    const entries = {
        1: {
            1: { is_invoiced: true, items: [] },
            2: { is_invoiced: false, items: [] },
            3: {
                is_invoiced: true,
                items: [
                    { amount: '300', is_invoiced: true },
                    { amount: '200', is_invoiced: false },
                ],
            },
        },
        2: { 1: { is_invoiced: false, items: [] } },
    };
    const cells = { '1-1': '1000', '1-2': '500', '1-3': '500', '2-1': '700', '3-1': '900' };

    it('keeps whole entries by flag and narrows split entries to matching items', () => {
        const view = applyInvoicedFilter([income, plainIncome, expense], entries, cells, 'yes');

        expect(view.categories.map((c) => c.id)).toEqual([1]);
        expect(view.cells['1-1']).toEqual({ amount: 1000, items: [] });
        expect(view.cells['1-2']).toBeNull();
        expect(view.cells['1-3']).toEqual({ amount: 300, items: [{ amount: '300', is_invoiced: true }] });
        expect(view.cells['1-4']).toBeNull();
    });

    it('shows the non invoiced side and hides expense categories', () => {
        const view = applyInvoicedFilter([income, plainIncome, expense], entries, cells, 'no');

        expect(view.categories.map((c) => c.id)).toEqual([1, 2]);
        expect(view.cells['1-1']).toBeNull();
        expect(view.cells['1-2']).toEqual({ amount: 500, items: [] });
        expect(view.cells['1-3']).toEqual({ amount: 200, items: [{ amount: '200', is_invoiced: false }] });
        expect(view.cells['2-1']).toEqual({ amount: 700, items: [] });
        expect(view.cells['3-1']).toBeUndefined();
    });

    it('treats unsaved typed cells by the category default', () => {
        const view = applyInvoicedFilter([income, plainIncome], {}, { '1-5': '10', '2-5': '20' }, 'yes');

        expect(view.categories.map((c) => c.id)).toEqual([1]);
        expect(view.cells['1-5']).toEqual({ amount: 10, items: [] });
    });

    it('drops rows without any matching cell', () => {
        const view = applyInvoicedFilter([income, plainIncome], entries, cells, 'yes');

        expect(view.categories.map((c) => c.id)).toEqual([1]);
    });
});
