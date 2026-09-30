import { parseAmount } from '@/lib/utils';

interface InvoicedCategory {
    id: number;
    type: 'income' | 'expense';
    is_invoiced: boolean;
}

interface InvoicedItem {
    amount: string;
    is_invoiced: boolean;
}

interface InvoicedEntry {
    is_invoiced: boolean;
    items: InvoicedItem[];
}

type EntriesByCategoryAndMonth = Record<number, Record<number, InvoicedEntry> | undefined>;

/**
 * Invoiced part of the yearly budget, computed from the live cell values so the
 * progress bar follows what the user is typing.
 *
 * - split entries count their flagged items only;
 * - whole entries count their cell value when flagged;
 * - cells with no saved entry yet follow the category default.
 */
export function computeInvoicedBudgetTotal(
    categories: InvoicedCategory[],
    entries: EntriesByCategoryAndMonth,
    cells: Record<string, string>,
): number {
    let total = 0;

    for (const category of categories) {
        if (category.type !== 'income') continue;

        for (let month = 1; month <= 12; month++) {
            const entry = entries[category.id]?.[month];

            if (entry && entry.items.length > 0) {
                total += entry.items
                    .filter((item) => item.is_invoiced)
                    .reduce((sum, item) => sum + parseAmount(item.amount), 0);
                continue;
            }

            const invoiced = entry ? entry.is_invoiced : category.is_invoiced;
            if (invoiced) {
                total += parseAmount(cells[`${category.id}-${month}`] ?? '');
            }
        }
    }

    return Math.round(total * 100) / 100;
}

export type InvoicedFilter = 'all' | 'yes' | 'no';

export interface FilteredCell<TItem extends InvoicedItem> {
    amount: number;
    /** Items matching the filter; empty for a whole (unsplit) entry. */
    items: TItem[];
}

export interface FilteredBudgetView<TCategory extends InvoicedCategory, TItem extends InvoicedItem> {
    /** Income categories with at least one matching cell, in the given order. */
    categories: TCategory[];
    /** Matching cell per `${categoryId}-${month}`; null when the cell has nothing on this side of the filter. */
    cells: Record<string, FilteredCell<TItem> | null>;
}

/**
 * Narrows the budget grid to the invoiced (or non invoiced) side.
 * Whole entries match by their own flag (or the category default when not saved yet);
 * split entries keep only the items on the requested side.
 */
export function applyInvoicedFilter<TCategory extends InvoicedCategory, TItem extends InvoicedItem>(
    categories: TCategory[],
    entries: Record<number, Record<number, { is_invoiced: boolean; items: TItem[] }> | undefined>,
    cells: Record<string, string>,
    filter: Exclude<InvoicedFilter, 'all'>,
): FilteredBudgetView<TCategory, TItem> {
    const wanted = filter === 'yes';
    const view: FilteredBudgetView<TCategory, TItem> = { categories: [], cells: {} };

    for (const category of categories) {
        if (category.type !== 'income') continue;

        let hasMatch = false;

        for (let month = 1; month <= 12; month++) {
            const key = `${category.id}-${month}`;
            const entry = entries[category.id]?.[month];
            let cell: FilteredCell<TItem> | null = null;

            if (entry && entry.items.length > 0) {
                const items = entry.items.filter((item) => item.is_invoiced === wanted);
                if (items.length > 0) {
                    cell = { amount: items.reduce((sum, item) => sum + parseAmount(item.amount), 0), items };
                }
            } else {
                const raw = cells[key] ?? '';
                const invoiced = entry ? entry.is_invoiced : category.is_invoiced;
                if (raw.trim() !== '' && invoiced === wanted) {
                    cell = { amount: parseAmount(raw), items: [] };
                }
            }

            view.cells[key] = cell;
            hasMatch = hasMatch || cell !== null;
        }

        if (hasMatch) {
            view.categories.push(category);
        }
    }

    return view;
}
