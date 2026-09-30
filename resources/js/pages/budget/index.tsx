import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ListPlus, Receipt } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { type BudgetItem, BudgetItemsDialog } from '@/components/shared/budget-items-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { applyInvoicedFilter, computeInvoicedBudgetTotal, type InvoicedFilter } from '@/lib/budget-invoiced';
import { cn, formatCurrency, parseAmount } from '@/lib/utils';

interface Category {
    id: number;
    name: string;
    type: 'income' | 'expense';
    /** Default invoiced flag for new entries and items of this category. */
    is_invoiced: boolean;
    color: string | null;
    sort_order: number;
}

interface EntryItemData {
    id: number;
    description: string;
    amount: string;
    is_invoiced: boolean;
}

interface EntryData {
    id: number;
    amount: string;
    /** Only meaningful when the entry has no items: split entries carry the flag per item. */
    is_invoiced: boolean;
    notes: string | null;
    items: EntryItemData[];
}

interface ItemsDialogTarget {
    category: Category;
    month: number;
    /** Increments on every open so the dialog remounts with fresh rows. */
    session: number;
    open: boolean;
}

interface Props {
    year: number;
    categories: Category[];
    entries: Record<number, Record<number, EntryData>>;
    invoiceLimit: number;
}

const MONTHS = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
const MONTH_NAMES = [
    'Gennaio',
    'Febbraio',
    'Marzo',
    'Aprile',
    'Maggio',
    'Giugno',
    'Luglio',
    'Agosto',
    'Settembre',
    'Ottobre',
    'Novembre',
    'Dicembre',
];

type CellKey = `${number}-${number}`;
type CellState = Record<CellKey, string>;

const INVOICED_FILTER_OPTIONS: { value: InvoicedFilter; label: string }[] = [
    { value: 'all', label: 'Tutti' },
    { value: 'yes', label: 'Sì' },
    { value: 'no', label: 'No' },
];

const INVOICED_FILTER_PARAM: Record<InvoicedFilter, string | null> = { all: null, yes: '1', no: '0' };

function readInvoicedFilter(): InvoicedFilter {
    if (typeof window === 'undefined') return 'all';
    const value = new URLSearchParams(window.location.search).get('invoiced');
    if (value === '1') return 'yes';
    if (value === '0') return 'no';
    return 'all';
}

function buildInitialCells(categories: Category[], entries: Record<number, Record<number, EntryData>>): CellState {
    const cells: CellState = {};
    for (const cat of categories) {
        for (let m = 1; m <= 12; m++) {
            const key: CellKey = `${cat.id}-${m}`;
            const entry = entries[cat.id]?.[m];
            cells[key] = entry ? entry.amount : '';
        }
    }
    return cells;
}

interface CategoryColorDotProps {
    color: string | null;
}

function CategoryColorDot({ color }: CategoryColorDotProps) {
    if (!color) return null;
    return (
        <span
            className="mr-2 inline-block size-2.5 shrink-0 rounded-full"
            style={{ backgroundColor: color }}
            aria-hidden="true"
        />
    );
}

interface TypeGroupHeaderRowProps {
    label: string;
    colSpan: number;
}

function TypeGroupHeaderRow({ label, colSpan }: TypeGroupHeaderRowProps) {
    return (
        <TableRow className="bg-muted/60 hover:bg-muted/60">
            <TableCell
                colSpan={colSpan}
                className="py-1.5 pl-3 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
            >
                {label}
            </TableCell>
        </TableRow>
    );
}

interface TotalsRowProps {
    label: string;
    categories: Category[];
    cells: CellState;
}

function TotalsRow({ label, categories, cells }: TotalsRowProps) {
    return (
        <TableRow className="bg-card font-semibold hover:bg-card">
            <TableCell className="sticky left-0 z-10 bg-card py-1.5 pl-3 text-xs">{label}</TableCell>
            {MONTHS.map((_, idx) => {
                const month = idx + 1;
                const total = categories.reduce((sum, cat) => {
                    const key: CellKey = `${cat.id}-${month}`;
                    return sum + parseAmount(cells[key] ?? '');
                }, 0);
                return (
                    <TableCell key={month} className="py-1.5 text-right text-xs tabular-nums">
                        {total !== 0 ? formatCurrency(total) : <span className="text-muted-foreground">—</span>}
                    </TableCell>
                );
            })}
        </TableRow>
    );
}

export default function BudgetIndex({ year, categories, entries, invoiceLimit }: Props) {
    const [cells, setCells] = useState<CellState>(() => buildInitialCells(categories, entries));
    const [invoicedFilter, setInvoicedFilter] = useState<InvoicedFilter>(readInvoicedFilter);

    const changeInvoicedFilter = (value: InvoicedFilter) => {
        setInvoicedFilter(value);
        const params = new URLSearchParams(window.location.search);
        const param = INVOICED_FILTER_PARAM[value];
        if (param === null) {
            params.delete('invoiced');
        } else {
            params.set('invoiced', param);
        }
        const query = params.toString();
        window.history.replaceState(window.history.state, '', query ? `?${query}` : window.location.pathname);
    };
    const initialCellsRef = useRef<CellState>(buildInitialCells(categories, entries));

    // Reset the cells when the server sends new data (year change or reload after save).
    const [sourceProps, setSourceProps] = useState({ categories, entries });
    if (sourceProps.categories !== categories || sourceProps.entries !== entries) {
        setSourceProps({ categories, entries });
        setCells(buildInitialCells(categories, entries));
    }

    useEffect(() => {
        initialCellsRef.current = buildInitialCells(categories, entries);
    }, [categories, entries]);

    const handleCellChange = useCallback((catId: number, month: number, value: string) => {
        const key: CellKey = `${catId}-${month}`;
        setCells((prev) => ({ ...prev, [key]: value }));
    }, []);

    const handleCellBlur = useCallback(
        (catId: number, month: number) => {
            const key: CellKey = `${catId}-${month}`;
            const currentValue = cells[key] ?? '';
            const initialValue = initialCellsRef.current[key] ?? '';

            if (currentValue === initialValue) return;

            const amount = currentValue.trim() !== '' ? String(parseAmount(currentValue)) : null;

            const existingEntry = entries[catId]?.[month];

            router.post(
                '/budget/bulk',
                {
                    year,
                    entries: [
                        {
                            category_id: catId,
                            month,
                            amount,
                            notes: existingEntry?.notes ?? null,
                        },
                    ],
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        initialCellsRef.current[key] = currentValue;
                        toast.success('Salvato');
                    },
                    onError: () => {
                        toast.error('Errore durante il salvataggio');
                    },
                },
            );
        },
        [cells, entries, year],
    );

    const handleToggleInvoiced = useCallback(
        (catId: number, month: number) => {
            const existingEntry = entries[catId]?.[month];
            if (!existingEntry) return;

            const nextInvoiced = !existingEntry.is_invoiced;

            router.post(
                '/budget/bulk',
                {
                    year,
                    entries: [
                        {
                            category_id: catId,
                            month,
                            amount: String(parseAmount(existingEntry.amount)),
                            notes: existingEntry.notes,
                            is_invoiced: nextInvoiced,
                        },
                    ],
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success(nextInvoiced ? 'Segnata come fatturata' : 'Segnata come non fatturata');
                    },
                    onError: () => {
                        toast.error('Errore durante il salvataggio');
                    },
                },
            );
        },
        [entries, year],
    );

    const [itemsDialogTarget, setItemsDialogTarget] = useState<ItemsDialogTarget | null>(null);
    const [itemsProcessing, setItemsProcessing] = useState(false);

    const handleOpenItems = useCallback((category: Category, month: number) => {
        setItemsDialogTarget((prev) => ({ category, month, session: (prev?.session ?? 0) + 1, open: true }));
    }, []);

    const closeItemsDialog = () => {
        setItemsDialogTarget((prev) => (prev ? { ...prev, open: false } : prev));
    };

    const handleSaveItems = (items: BudgetItem[]) => {
        if (!itemsDialogTarget) return;

        router.put(
            '/budget/items',
            {
                year,
                month: itemsDialogTarget.month,
                category_id: itemsDialogTarget.category.id,
                items: items.map((item) => ({
                    description: item.description,
                    amount: String(item.amount),
                    is_invoiced: item.is_invoiced,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setItemsProcessing(true),
                onFinish: () => setItemsProcessing(false),
                onSuccess: () => {
                    closeItemsDialog();
                    toast.success('Salvato');
                },
                onError: () => {
                    toast.error('Errore durante il salvataggio');
                },
            },
        );
    };

    const dialogEntry = itemsDialogTarget
        ? entries[itemsDialogTarget.category.id]?.[itemsDialogTarget.month]
        : undefined;

    const navigateYear = (delta: number) => {
        const param = INVOICED_FILTER_PARAM[invoicedFilter];
        router.get(
            '/budget',
            { year: year + delta, ...(param === null ? {} : { invoiced: param }) },
            { preserveScroll: false },
        );
    };

    const incomeCategories = categories.filter((c) => c.type === 'income').sort((a, b) => a.sort_order - b.sort_order);

    const expenseCategories = categories
        .filter((c) => c.type === 'expense')
        .sort((a, b) => a.sort_order - b.sort_order);

    const totalColumns = 13;

    const invoicedBudgetTotal = computeInvoicedBudgetTotal(categories, entries, cells);

    const filteredView =
        invoicedFilter === 'all' ? null : applyInvoicedFilter(categories, entries, cells, invoicedFilter);
    const filteredCells: CellState = {};
    if (filteredView) {
        for (const [key, cell] of Object.entries(filteredView.cells)) {
            filteredCells[key as CellKey] = cell ? String(cell.amount) : '';
        }
    }

    const invoicePercentage = invoiceLimit > 0 ? (invoicedBudgetTotal / invoiceLimit) * 100 : 0;
    const isOverLimit = invoicedBudgetTotal > invoiceLimit && invoiceLimit > 0;

    return (
        <AppLayout>
            <Head title="Budget Previsionale" />

            <div className="flex h-[calc(100vh-8rem)] flex-col gap-4">
                {/* Page header */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Budget Previsionale</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Pianifica le entrate e uscite mensili per l&apos;anno selezionato.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        {/* Invoiced filter */}
                        <div
                            className="flex items-center gap-1 rounded-lg border bg-card px-1 py-1"
                            role="group"
                            aria-label="Filtro fatturati"
                        >
                            <span className="flex items-center gap-1 pr-1 pl-2 text-xs font-medium text-muted-foreground">
                                <Receipt className="size-3.5" aria-hidden="true" />
                                Fatturati
                            </span>
                            {INVOICED_FILTER_OPTIONS.map((option) => (
                                <Button
                                    key={option.value}
                                    variant={invoicedFilter === option.value ? 'secondary' : 'ghost'}
                                    size="sm"
                                    className="h-8 px-3 text-xs"
                                    aria-pressed={invoicedFilter === option.value}
                                    onClick={() => changeInvoicedFilter(option.value)}
                                >
                                    {option.label}
                                </Button>
                            ))}
                        </div>

                        {/* Year selector */}
                        <div className="flex items-center gap-1 rounded-lg border bg-card px-1 py-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                onClick={() => navigateYear(-1)}
                                aria-label="Anno precedente"
                            >
                                <ChevronLeft className="size-4" />
                            </Button>
                            <span className="min-w-12 text-center text-sm font-semibold tabular-nums">{year}</span>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                onClick={() => navigateYear(1)}
                                aria-label="Anno successivo"
                            >
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Invoice limit progress */}
                {invoiceLimit > 0 && (
                    <div className="rounded-lg border bg-card p-4 shadow-xs">
                        <div className="flex items-center justify-between text-sm">
                            <span className="font-medium">Fatturato budget</span>
                            <span
                                className={cn(
                                    'font-semibold tabular-nums',
                                    isOverLimit ? 'text-destructive' : 'text-foreground',
                                )}
                            >
                                {formatCurrency(invoicedBudgetTotal)} / {formatCurrency(invoiceLimit)}
                            </span>
                        </div>
                        <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded-full transition-all',
                                    isOverLimit
                                        ? 'bg-destructive'
                                        : invoicePercentage > 80
                                          ? 'bg-amber-500'
                                          : 'bg-emerald-500',
                                )}
                                style={{ width: `${Math.min(invoicePercentage, 100)}%` }}
                            />
                        </div>
                        {isOverLimit && (
                            <p className="mt-1.5 text-xs font-medium text-destructive">
                                Superato del {formatCurrency(invoicedBudgetTotal - invoiceLimit)}
                            </p>
                        )}
                    </div>
                )}

                {/* Spreadsheet table */}
                <div className="min-h-0 flex-1 rounded-lg border bg-card shadow-xs [&_[data-slot=table-container]]:h-full [&_[data-slot=table-container]]:overflow-auto">
                    <Table className="table-fixed text-xs">
                        <TableHeader className="sticky top-0 z-30 bg-muted">
                            <TableRow className="bg-muted/40 hover:bg-muted/40">
                                <TableHead className="sticky left-0 z-40 w-[14%] bg-muted/40 pl-3 font-semibold">
                                    Categoria
                                </TableHead>
                                {MONTHS.map((month) => (
                                    <TableHead key={month} className="w-[7.16%] text-center font-semibold">
                                        {month}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>

                        {filteredView ? (
                            <TableBody>
                                <TypeGroupHeaderRow
                                    label={invoicedFilter === 'yes' ? 'Entrate fatturate' : 'Entrate non fatturate'}
                                    colSpan={totalColumns}
                                />
                                {filteredView.categories.map((cat) => (
                                    <FilteredBudgetRow
                                        key={cat.id}
                                        category={cat}
                                        cells={filteredView.cells}
                                        onOpenItems={handleOpenItems}
                                    />
                                ))}
                                {filteredView.categories.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={totalColumns}
                                            className="py-4 text-center text-sm text-muted-foreground"
                                        >
                                            Nessuna voce {invoicedFilter === 'yes' ? 'fatturata' : 'non fatturata'}
                                        </TableCell>
                                    </TableRow>
                                )}
                                <TotalsRow
                                    label={invoicedFilter === 'yes' ? 'Totale fatturato' : 'Totale non fatturato'}
                                    categories={filteredView.categories}
                                    cells={filteredCells}
                                />
                            </TableBody>
                        ) : (
                            <TableBody>
                                {/* Income group */}
                                <TypeGroupHeaderRow label="Entrate" colSpan={totalColumns} />
                                {incomeCategories.map((cat) => (
                                    <BudgetRow
                                        key={cat.id}
                                        category={cat}
                                        cells={cells}
                                        entries={entries[cat.id]}
                                        onChange={handleCellChange}
                                        onBlur={handleCellBlur}
                                        onOpenItems={handleOpenItems}
                                        onToggleInvoiced={handleToggleInvoiced}
                                    />
                                ))}
                                {incomeCategories.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={totalColumns}
                                            className="py-4 text-center text-sm text-muted-foreground"
                                        >
                                            Nessuna categoria di entrata
                                        </TableCell>
                                    </TableRow>
                                )}
                                <TotalsRow label="Totale Entrate" categories={incomeCategories} cells={cells} />

                                {/* Expense group */}
                                <TypeGroupHeaderRow label="Uscite" colSpan={totalColumns} />
                                {expenseCategories.map((cat) => (
                                    <BudgetRow
                                        key={cat.id}
                                        category={cat}
                                        cells={cells}
                                        entries={entries[cat.id]}
                                        onChange={handleCellChange}
                                        onBlur={handleCellBlur}
                                        onOpenItems={handleOpenItems}
                                    />
                                ))}
                                {expenseCategories.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={totalColumns}
                                            className="py-4 text-center text-sm text-muted-foreground"
                                        >
                                            Nessuna categoria di uscita
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        )}

                        {!filteredView && (
                            <tfoot className="sticky bottom-0 z-20 border-t bg-card font-medium">
                                <TotalsRow label="Totale Uscite" categories={expenseCategories} cells={cells} />
                                <NetRow
                                    incomeCategories={incomeCategories}
                                    expenseCategories={expenseCategories}
                                    cells={cells}
                                />
                            </tfoot>
                        )}
                    </Table>
                </div>
            </div>

            {itemsDialogTarget && (
                <BudgetItemsDialog
                    key={itemsDialogTarget.session}
                    open={itemsDialogTarget.open}
                    onOpenChange={(open) => {
                        if (!open) closeItemsDialog();
                    }}
                    title={`Dettaglio budget · ${itemsDialogTarget.category.name}`}
                    subtitle={`${MONTH_NAMES[itemsDialogTarget.month - 1]} ${year} — il budget del mese è la somma delle righe.`}
                    initialItems={dialogEntry?.items ?? []}
                    initialAmount={cells[`${itemsDialogTarget.category.id}-${itemsDialogTarget.month}`] ?? ''}
                    invoiced={
                        itemsDialogTarget.category.type === 'income'
                            ? {
                                  defaultValue: itemsDialogTarget.category.is_invoiced,
                                  initialAmountValue:
                                      dialogEntry?.is_invoiced ?? itemsDialogTarget.category.is_invoiced,
                              }
                            : undefined
                    }
                    processing={itemsProcessing}
                    onSubmit={handleSaveItems}
                />
            )}
        </AppLayout>
    );
}

// ─── Sub-components ──────────────────────────────────────────────────────────

interface FilteredBudgetRowProps {
    category: Category;
    cells: Record<string, { amount: number; items: EntryItemData[] } | null>;
    onOpenItems: (category: Category, month: number) => void;
}

/** Read-only row shown while the invoiced filter is active: only the matching side of each cell. */
function FilteredBudgetRow({ category, cells, onOpenItems }: FilteredBudgetRowProps) {
    return (
        <TableRow className="group">
            <TableCell className="sticky left-0 z-10 bg-card py-1.5 pl-3 group-hover:bg-muted/50">
                <div className="flex items-center">
                    <CategoryColorDot color={category.color} />
                    <span className="text-xs font-medium">{category.name}</span>
                </div>
            </TableCell>
            {MONTHS.map((_, idx) => {
                const month = idx + 1;
                const cell = cells[`${category.id}-${month}`] ?? null;
                const label = `${category.name} - ${MONTHS[idx]}`;

                if (!cell) {
                    return (
                        <TableCell key={month} className="p-1 text-right text-xs text-muted-foreground">
                            <span className="block h-7 px-3 leading-7" aria-label={`${label}: nessuna voce`}>
                                —
                            </span>
                        </TableCell>
                    );
                }

                if (cell.items.length > 0) {
                    const summary = cell.items
                        .map((item) => `${item.description}: ${formatCurrency(parseAmount(item.amount))}`)
                        .join('\n');
                    return (
                        <TableCell key={month} className="p-1">
                            <button
                                type="button"
                                onClick={() => onOpenItems(category, month)}
                                className="relative flex h-7 w-full items-center justify-end rounded-md border border-dashed border-primary/40 bg-primary/5 px-3 text-xs tabular-nums hover:bg-primary/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                title={summary}
                                aria-label={`${label}: ${cell.items.length} righe, modifica dettaglio`}
                            >
                                <span className="absolute -top-1.5 -left-1.5 z-10 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-primary px-1 text-[9px] leading-none font-semibold text-primary-foreground ring-2 ring-card">
                                    {cell.items.length}
                                </span>
                                {formatCurrency(cell.amount)}
                            </button>
                        </TableCell>
                    );
                }

                return (
                    <TableCell key={month} className="p-1 text-right text-xs tabular-nums">
                        <span className="block h-7 px-3 leading-7" aria-label={label}>
                            {formatCurrency(cell.amount)}
                        </span>
                    </TableCell>
                );
            })}
        </TableRow>
    );
}

interface BudgetRowProps {
    category: Category;
    cells: CellState;
    entries: Record<number, EntryData> | undefined;
    onChange: (catId: number, month: number, value: string) => void;
    onBlur: (catId: number, month: number) => void;
    onOpenItems: (category: Category, month: number) => void;
    /** Only passed for income rows: expense entries are never invoiced. */
    onToggleInvoiced?: (catId: number, month: number) => void;
}

function BudgetRow({ category, cells, entries, onChange, onBlur, onOpenItems, onToggleInvoiced }: BudgetRowProps) {
    const canInvoice = category.type === 'income' && onToggleInvoiced !== undefined;

    return (
        <TableRow className="group">
            <TableCell className="sticky left-0 z-10 bg-card py-1.5 pl-3 group-hover:bg-muted/50">
                <div className="flex items-center">
                    <CategoryColorDot color={category.color} />
                    <span className="text-xs font-medium">{category.name}</span>
                </div>
            </TableCell>
            {MONTHS.map((_, idx) => {
                const month = idx + 1;
                const key: CellKey = `${category.id}-${month}`;
                const entry = entries?.[month];
                const items = entry?.items ?? [];
                const label = `${category.name} - ${MONTHS[idx]}`;

                if (items.length > 0) {
                    const invoicedCount = items.filter((item) => item.is_invoiced).length;
                    const summary = items
                        .map(
                            (item) =>
                                `${item.description}: ${formatCurrency(parseAmount(item.amount))}${canInvoice && item.is_invoiced ? ' (fatturata)' : ''}`,
                        )
                        .concat(canInvoice ? [`${invoicedCount}/${items.length} fatturate`] : [])
                        .join('\n');
                    return (
                        <TableCell key={month} className="p-1">
                            <button
                                type="button"
                                onClick={() => onOpenItems(category, month)}
                                className="relative flex h-7 w-full items-center justify-end rounded-md border border-dashed border-primary/40 bg-primary/5 px-3 text-xs tabular-nums hover:bg-primary/10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                title={summary}
                                aria-label={`${label}: ${items.length} righe, modifica dettaglio`}
                            >
                                <span className="absolute -top-1.5 -left-1.5 z-10 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-primary px-1 text-[9px] leading-none font-semibold text-primary-foreground ring-2 ring-card">
                                    {items.length}
                                </span>
                                {cells[key] ?? ''}
                            </button>
                        </TableCell>
                    );
                }

                return (
                    <TableCell key={month} className="p-1">
                        <div className="group/cell relative">
                            <Input
                                type="text"
                                inputMode="decimal"
                                value={cells[key] ?? ''}
                                onChange={(e) => onChange(category.id, month, e.target.value)}
                                onBlur={() => onBlur(category.id, month)}
                                onFocus={(e) => e.target.select()}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' && e.shiftKey) {
                                        e.preventDefault();
                                        onOpenItems(category, month);
                                    }
                                }}
                                className="h-7 w-full text-right text-xs! tabular-nums"
                                placeholder="0,00"
                                aria-label={label}
                                title="Maiusc+Invio per scorporare in più righe"
                            />
                            {canInvoice && entry && (
                                <button
                                    type="button"
                                    tabIndex={-1}
                                    onClick={() => onToggleInvoiced(category.id, month)}
                                    aria-pressed={entry.is_invoiced}
                                    className={cn(
                                        'absolute -top-1.5 -left-1.5 z-10 flex size-4 items-center justify-center rounded-full ring-2 ring-card transition-opacity focus-visible:opacity-100',
                                        entry.is_invoiced
                                            ? 'bg-primary text-primary-foreground hover:bg-primary/80'
                                            : 'bg-muted text-muted-foreground opacity-0 group-hover/cell:opacity-100 hover:text-foreground',
                                    )}
                                    aria-label={`${label}: ${entry.is_invoiced ? 'fatturata, segna come non fatturata' : 'non fatturata, segna come fatturata'}`}
                                    title={entry.is_invoiced ? 'Fatturata' : 'Non fatturata'}
                                >
                                    <Receipt className="size-2.5" />
                                </button>
                            )}
                            <button
                                type="button"
                                tabIndex={-1}
                                onClick={() => onOpenItems(category, month)}
                                className="absolute top-1/2 left-1 flex size-5 -translate-y-1/2 items-center justify-center rounded-sm text-muted-foreground opacity-0 group-hover/cell:opacity-100 hover:bg-muted hover:text-foreground focus-visible:opacity-100"
                                aria-label={`${label}: scorpora in più righe`}
                                title="Scorpora in più righe"
                            >
                                <ListPlus className="size-3.5" />
                            </button>
                        </div>
                    </TableCell>
                );
            })}
        </TableRow>
    );
}

interface NetRowProps {
    incomeCategories: Category[];
    expenseCategories: Category[];
    cells: CellState;
}

function NetRow({ incomeCategories, expenseCategories, cells }: NetRowProps) {
    return (
        <TableRow className="bg-card hover:bg-card">
            <TableCell className="sticky left-0 z-10 bg-card py-1.5 pl-3 text-xs font-bold">Saldo Netto</TableCell>
            {MONTHS.map((_, idx) => {
                const month = idx + 1;
                const income = incomeCategories.reduce((sum, cat) => {
                    const key: CellKey = `${cat.id}-${month}`;
                    return sum + parseAmount(cells[key] ?? '');
                }, 0);
                const expense = expenseCategories.reduce((sum, cat) => {
                    const key: CellKey = `${cat.id}-${month}`;
                    return sum + parseAmount(cells[key] ?? '');
                }, 0);
                const net = income - expense;
                return (
                    <TableCell
                        key={month}
                        className={cn(
                            'py-1.5 text-right text-xs font-bold tabular-nums',
                            net > 0 && 'text-emerald-600 dark:text-emerald-400',
                            net < 0 && 'text-destructive',
                            net === 0 && 'text-muted-foreground',
                        )}
                    >
                        {net !== 0 ? formatCurrency(net) : <span>—</span>}
                    </TableCell>
                );
            })}
        </TableRow>
    );
}
