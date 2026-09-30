import type { CategoryKind } from '@/lib/actual-variance';

export interface ActualCategory {
    id: number;
    name: string;
    type: CategoryKind;
    color: string | null;
    sort_order: number;
}

export interface ActualLine {
    key: string;
    kind: 'bank' | 'manual';
    id: number;
    /** Y-m-d */
    date: string;
    description: string;
    /** Signed by category type: spending on an expense category is positive. */
    amount: number;
    /** Bank sign (negative = money out); only for bank lines. */
    bank_amount: number | null;
    kind_label: string | null;
}

export interface MonthCell {
    month: number;
    budget: number;
    actual: number;
    status: 'closed' | 'current' | 'future';
}

export interface VarianceRow {
    category_id: number | null;
    type: CategoryKind;
    months: MonthCell[];
    avg_budget: number | null;
    avg_actual: number | null;
    variance: number | null;
    variance_pct: number | null;
    peak: { month: number; actual: number } | null;
}

export interface VarianceReport {
    closed_months: number;
    rows: VarianceRow[];
    totals: { income: VarianceRow; expense: VarianceRow; net: VarianceRow };
}

/** "2026-08-15" → "15/08" without going through Date (no timezone shift). */
export function shortDate(date: string): string {
    const [, month, day] = date.split('-');
    return `${day}/${month}`;
}
