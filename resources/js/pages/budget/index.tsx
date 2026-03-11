import { Head, router, useForm } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Save } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
}

const MONTHS = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

// cell state: keyed by `${categoryId}-${month}`
type CellKey = `${number}-${number}`;
type CellState = Record<CellKey, string>;

function buildInitialCells(
    categories: Category[],
    entries: Record<number, Record<number, EntryData>>,
): CellState {
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
                className="py-2 pl-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground"
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
        <TableRow className="bg-muted/30 font-semibold hover:bg-muted/30">
            <TableCell className="sticky left-0 z-10 bg-muted/30 py-2 pl-3 text-sm">
                {label}
            </TableCell>
            {MONTHS.map((_, idx) => {
                const month = idx + 1;
                const total = categories.reduce((sum, cat) => {
                    const key: CellKey = `${cat.id}-${month}`;
                    return sum + parseAmount(cells[key] ?? '');
                }, 0);
                return (
                    <TableCell key={month} className="py-2 text-right text-sm tabular-nums">
                        {total !== 0 ? formatCurrency(total) : <span className="text-muted-foreground">—</span>}
                    </TableCell>
                );
            })}
        </TableRow>
    );
}

export default function BudgetIndex({ year, categories, entries }: Props) {
    const [cells, setCells] = useState<CellState>(() => buildInitialCells(categories, entries));
    const [dirty, setDirty] = useState(false);

    // Reset when year/entries change (navigation)
    useEffect(() => {
        setCells(buildInitialCells(categories, entries));
        setDirty(false);
    }, [year, categories, entries]);

    const { post, processing } = useForm();

    const handleCellChange = useCallback((catId: number, month: number, value: string) => {
        const key: CellKey = `${catId}-${month}`;
        setCells((prev) => ({ ...prev, [key]: value }));
        setDirty(true);
    }, []);

    const handleSave = () => {
        const payload: Array<{ category_id: number; month: number; amount: string | null; notes: string | null }> = [];

        for (const cat of categories) {
            for (let m = 1; m <= 12; m++) {
                const key: CellKey = `${cat.id}-${m}`;
                const rawValue = cells[key] ?? '';
                const existingEntry = entries[cat.id]?.[m];

                // Only include cells that have a value or had an existing entry
                if (rawValue !== '' || existingEntry) {
                    const amount = rawValue !== '' ? String(parseAmount(rawValue)) : null;
                    payload.push({
                        category_id: cat.id,
                        month: m,
                        amount,
                        notes: existingEntry?.notes ?? null,
                    });
                }
            }
        }

        // useForm doesn't support dynamic data well here; use router.post directly
        router.post(
            '/budget/bulk',
            { year, entries: payload },
            {
                preserveScroll: true,
                onSuccess: () => setDirty(false),
            },
        );
    };

    const navigateYear = (delta: number) => {
        router.get('/budget', { year: year + delta }, { preserveScroll: false });
    };

    const incomeCategories = categories
        .filter((c) => c.type === 'income')
        .sort((a, b) => a.sort_order - b.sort_order);

    const expenseCategories = categories
        .filter((c) => c.type === 'expense')
        .sort((a, b) => a.sort_order - b.sort_order);

    const totalColumns = 13; // category name + 12 months

    return (
        <AppLayout>
            <Head title="Budget Previsionale" />

            <div className="space-y-6">
                {/* Page header */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Budget Previsionale</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Pianifica le entrate e uscite mensili per l&apos;anno selezionato.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
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
                            <span className="min-w-12 text-center text-sm font-semibold tabular-nums">
                                {year}
                            </span>
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

                        <Button
                            onClick={handleSave}
                            disabled={!dirty || processing}
                            className="gap-2"
                        >
                            <Save className="size-4" />
                            Salva
                        </Button>
                    </div>
                </div>

                {/* Spreadsheet table */}
                <div className="rounded-lg border bg-card shadow-xs">
                    <Table className="table-fixed">
                        <TableHeader>
                            <TableRow className="bg-muted/40 hover:bg-muted/40">
                                <TableHead className="sticky left-0 z-20 w-[14%] bg-muted/40 pl-3 font-semibold">
                                    Categoria
                                </TableHead>
                                {MONTHS.map((month) => (
                                    <TableHead
                                        key={month}
                                        className="w-[7.16%] text-center font-semibold"
                                    >
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
                            <TotalsRow
                                label="Totale Entrate"
                                categories={incomeCategories}
                                cells={cells}
                            />

                            {/* Expense group */}
                            <TypeGroupHeaderRow label="Uscite" colSpan={totalColumns} />
                            {expenseCategories.map((cat) => (
                                <BudgetRow
                                    key={cat.id}
                                    category={cat}
                                    cells={cells}
                                    onChange={handleCellChange}
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
                            <TotalsRow
                                label="Totale Uscite"
                                categories={expenseCategories}
                                cells={cells}
                            />
                        </TableBody>

                        <TableFooter>
                            <NetRow
                                incomeCategories={incomeCategories}
                                expenseCategories={expenseCategories}
                                cells={cells}
                            />
                        </TableFooter>
                    </Table>
                </div>

                {dirty && (
                    <p className="text-right text-xs text-muted-foreground">
                        Hai modifiche non salvate. Premi &quot;Salva&quot; per confermare.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}

// ─── Sub-components ──────────────────────────────────────────────────────────

interface BudgetRowProps {
    category: Category;
    cells: CellState;
    onChange: (catId: number, month: number, value: string) => void;
}

function BudgetRow({ category, cells, onChange }: BudgetRowProps) {
    return (
        <TableRow className="group">
            <TableCell className="sticky left-0 z-10 bg-card py-1.5 pl-3 group-hover:bg-muted/50">
                <div className="flex items-center">
                    <CategoryColorDot color={category.color} />
                    <span className="text-sm font-medium">{category.name}</span>
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
                            onFocus={(e) => e.target.select()}
                            className="h-8 w-full text-right tabular-nums"
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
        <TableRow className="hover:bg-muted/50">
            <TableCell className="sticky left-0 z-10 bg-muted/50 py-2 pl-3 text-sm font-bold">
                Saldo Netto
            </TableCell>
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
                            'py-2 text-right text-sm font-bold tabular-nums',
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
