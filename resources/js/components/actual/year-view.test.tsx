import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { ActualCategory, VarianceReport, VarianceRow } from './types';
import { YearView } from './year-view';

function row(
    categoryId: number | null,
    type: 'income' | 'expense',
    budget: number,
    actual: number[],
    avg: [number | null, number | null],
    peak: VarianceRow['peak'],
): VarianceRow {
    const variance = avg[0] !== null && avg[1] !== null ? avg[1] - avg[0] : null;
    return {
        category_id: categoryId,
        type,
        months: Array.from({ length: 12 }, (_, i) => ({
            month: i + 1,
            budget,
            actual: actual[i] ?? 0,
            status: i < 8 ? 'closed' : i === 8 ? 'current' : 'future',
        })),
        avg_budget: avg[0],
        avg_actual: avg[1],
        variance,
        variance_pct: variance !== null && avg[0] ? variance / avg[0] : null,
        peak,
    };
}

const categories: ActualCategory[] = [
    { id: 1, name: 'Spesa', type: 'expense', color: null, sort_order: 1 },
    { id: 2, name: 'Pranzi/cene', type: 'expense', color: null, sort_order: 2 },
];

const report: VarianceReport = {
    closed_months: 8,
    rows: [
        row(1, 'expense', 500, [470, 470, 470, 470, 470, 470, 470, 470], [500, 470], { month: 1, actual: 470 }),
        row(2, 'expense', 300, [300, 350, 420, 420, 420, 420, 450, 580], [300, 420], { month: 8, actual: 580 }),
    ],
    totals: {
        income: row(null, 'income', 0, [], [0, 0], null),
        expense: row(null, 'expense', 800, [], [800, 890], null),
        net: row(null, 'income', -800, [], [-800, -890], null),
    },
};

describe('YearView', () => {
    it('sorts categories by variance and shows average, variance and peak', () => {
        render(<YearView year={2026} categories={categories} report={report} onOpenMonth={() => {}} />);

        const rows = screen.getAllByRole('row').filter((r) => r.dataset.categoryId);
        expect(rows.map((r) => r.dataset.categoryId)).toEqual(['2', '1']);
        const dining = within(rows[0]);
        expect(dining.getByText('+40%')).toBeInTheDocument();
        expect(dining.getByText(/ago/i)).toBeInTheDocument();
    });

    it('opens the month when a cell is clicked', () => {
        const onOpenMonth = vi.fn();
        render(<YearView year={2026} categories={categories} report={report} onOpenMonth={onOpenMonth} />);

        fireEvent.click(screen.getByRole('button', { name: /Pranzi\/cene agosto/i }));

        expect(onOpenMonth).toHaveBeenCalledWith(8, 2);
    });

    it('shows dashes when no month is closed yet', () => {
        const future: VarianceReport = {
            closed_months: 0,
            rows: [row(2, 'expense', 300, [], [null, null], null)],
            totals: {
                income: row(null, 'income', 0, [], [null, null], null),
                expense: row(null, 'expense', 300, [], [null, null], null),
                net: row(null, 'income', -300, [], [null, null], null),
            },
        };
        render(<YearView year={2027} categories={categories} report={future} onOpenMonth={() => {}} />);

        expect(screen.getByText(/Nessun mese chiuso nel 2027/)).toBeInTheDocument();
    });
});
