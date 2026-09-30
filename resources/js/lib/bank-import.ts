export interface ImportCategory {
    id: number;
    name: string;
    type: 'income' | 'expense';
    color: string | null;
}

export interface ImportTransaction {
    id: number;
    kind: string;
    kind_label: string;
    accounting_date: string;
    operation_date: string;
    amount: number;
    merchant_key: string;
    merchant_label: string;
    raw_description: string;
    payment_instrument: string | null;
    bank_category: string | null;
    category_id: number | null;
    source: string | null;
    source_label: string | null;
    confidence: number | null;
    status: 'to_review' | 'auto' | 'confirmed' | 'excluded';
}

export interface MerchantGroup {
    merchantKey: string;
    label: string;
    kindLabel: string;
    transactions: ImportTransaction[];
    total: number;
    /** Shared category, or null when missing or mixed. */
    categoryId: number | null;
    mixed: boolean;
}

export const EXCLUDE_VALUE = 'exclude';

/** Groups transactions by merchant: most frequent first, then largest amounts, so few choices cover most movements. */
export function groupByMerchant(transactions: ImportTransaction[]): MerchantGroup[] {
    const groups = new Map<string, MerchantGroup>();

    for (const transaction of transactions) {
        const group = groups.get(transaction.merchant_key) ?? {
            merchantKey: transaction.merchant_key,
            label: transaction.merchant_label,
            kindLabel: transaction.kind_label,
            transactions: [],
            total: 0,
            categoryId: null,
            mixed: false,
        };
        group.transactions.push(transaction);
        group.total += transaction.amount;
        groups.set(transaction.merchant_key, group);
    }

    for (const group of groups.values()) {
        const ids = new Set(group.transactions.map((t) => t.category_id));
        group.mixed = ids.size > 1;
        group.categoryId = ids.size === 1 ? group.transactions[0].category_id : null;
    }

    return [...groups.values()].sort(
        (a, b) => b.transactions.length - a.transactions.length || Math.abs(b.total) - Math.abs(a.total),
    );
}

/** Income categories only fit money coming in; expense categories fit expenses and refunds. */
export function categoriesFor(amount: number, categories: ImportCategory[]): ImportCategory[] {
    return categories.filter((c) => c.type === 'expense' || amount > 0);
}
