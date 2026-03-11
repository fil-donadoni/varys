import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn, formatCurrency } from '@/lib/utils';

interface Category {
    id: number;
    name: string;
    type: 'income' | 'expense';
    color: string | null;
    sort_order: number;
}

interface EntryData {
    id: number;
    amount: string;
    notes: string | null;
}

interface Props {
    year: number;
    categories: Category[];
    entries: Record<number, Record<number, EntryData>>;
    invoicedCategoryIds: number[];
    limiteFatturato: number;
}

const MONTHS = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

type CellKey = `${number}-${number}`;
type CellState = Record<CellKey, string>;

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

function parseAmount(raw: string): number {
    const cleaned = raw.replace(',', '.').replace(/[^\d.-]/g, '');
    const val = parseFloat(cleaned);
    return isNaN(val) ? 0 : val;
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

export default function BudgetIndex({ year, categories, entries, invoicedCategoryIds, limiteFatturato }: Props) {
    const [cells, setCells] = useState<CellState>(() => buildInitialCells(categories, entries));
    const initialCellsRef = useRef<CellState>(buildInitialCells(categories, entries));

    useEffect(() => {
        const initial = buildInitialCells(categories, entries);
        setCells(initial);
        initialCellsRef.current = initial;
    }, [year, categories, entries]);

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

            const amount = currentValue !== '' ? String(parseAmount(currentValue)) : null;
            if (amount === null || amount === '0') return;

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

    const navigateYear = (delta: number) => {
        router.get('/budget', { year: year + delta }, { preserveScroll: false });
    };

    const incomeCategories = categories.filter((c) => c.type === 'income').sort((a, b) => a.sort_order - b.sort_order);

    const expenseCategories = categories
        .filter((c) => c.type === 'expense')
        .sort((a, b) => a.sort_order - b.sort_order);

    const totalColumns = 13;

    // Compute invoiced budget total from current cell values
    const invoicedBudgetTotal = invoicedCategoryIds.reduce((total, catId) => {
        for (let m = 1; m <= 12; m++) {
            const key: CellKey = `${catId}-${m}`;
            total += parseAmount(cells[key] ?? '');
        }
        return total;
    }, 0);

    const invoicePercentage = limiteFatturato > 0 ? (invoicedBudgetTotal / limiteFatturato) * 100 : 0;
    const isOverLimit = invoicedBudgetTotal > limiteFatturato && limiteFatturato > 0;

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

                {/* Invoice limit progress */}
                {limiteFatturato > 0 && (
                    <div className="rounded-lg border bg-card p-4 shadow-xs">
                        <div className="flex items-center justify-between text-sm">
                            <span className="font-medium">Fatturato budget</span>
                            <span
                                className={cn(
                                    'font-semibold tabular-nums',
                                    isOverLimit ? 'text-destructive' : 'text-foreground',
                                )}
                            >
                                {formatCurrency(invoicedBudgetTotal)} / {formatCurrency(limiteFatturato)}
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
                                Superato del {formatCurrency(invoicedBudgetTotal - limiteFatturato)}
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

                        <TableBody>
                            {/* Income group */}
                            <TypeGroupHeaderRow label="Entrate" colSpan={totalColumns} />
                            {incomeCategories.map((cat) => (
                                <BudgetRow
                                    key={cat.id}
                                    category={cat}
                                    cells={cells}
                                    onChange={handleCellChange}
                                    onBlur={handleCellBlur}
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
                                    onChange={handleCellChange}
                                    onBlur={handleCellBlur}
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

                        <tfoot className="sticky bottom-0 z-20 border-t bg-card font-medium">
                            <TotalsRow label="Totale Uscite" categories={expenseCategories} cells={cells} />
                            <NetRow
                                incomeCategories={incomeCategories}
                                expenseCategories={expenseCategories}
                                cells={cells}
                            />
                        </tfoot>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}

// ─── Sub-components ──────────────────────────────────────────────────────────

interface BudgetRowProps {
    category: Category;
    cells: CellState;
    onChange: (catId: number, month: number, value: string) => void;
    onBlur: (catId: number, month: number) => void;
}

function BudgetRow({ category, cells, onChange, onBlur }: BudgetRowProps) {
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
                return (
                    <TableCell key={month} className="p-1">
                        <Input
                            type="text"
                            inputMode="decimal"
                            value={cells[key] ?? ''}
                            onChange={(e) => onChange(category.id, month, e.target.value)}
                            onBlur={() => onBlur(category.id, month)}
                            onFocus={(e) => e.target.select()}
                            className="h-7 w-full text-right text-xs tabular-nums"
                            placeholder="0,00"
                            aria-label={`${category.name} - ${MONTHS[idx]}`}
                        />
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
