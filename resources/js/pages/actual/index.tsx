import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn, formatCurrency, formatMonth } from '@/lib/utils';

interface Category {
    id: number;
    name: string;
    type: 'income' | 'expense';
    color: string | null;
    sort_order: number;
}

interface ActualEntry {
    id: number;
    amount: string;
    description: string | null;
    notes: string | null;
}

interface BudgetEntry {
    id: number;
    amount: string;
}

interface Props {
    year: number;
    month: number;
    categories: Category[];
    entries: Record<number, ActualEntry>;
    budgetEntries: Record<number, BudgetEntry>;
}

type RowState = {
    amount: string;
    description: string;
};

type FormState = Record<number, RowState>;

function buildInitialForm(categories: Category[], entries: Record<number, ActualEntry>): FormState {
    const form: FormState = {};
    for (const cat of categories) {
        const entry = entries[cat.id];
        form[cat.id] = {
            amount: entry ? entry.amount : '',
            description: entry?.description ?? '',
        };
    }
    return form;
}

function parseAmount(raw: string): number {
    const cleaned = raw.replace(',', '.').replace(/[^\d.-]/g, '');
    const val = parseFloat(cleaned);
    return isNaN(val) ? 0 : val;
}

function CategoryColorDot({ color }: { color: string | null }) {
    if (!color) return null;
    return (
        <span
            className="mr-2 inline-block size-2.5 shrink-0 rounded-full"
            style={{ backgroundColor: color }}
            aria-hidden="true"
        />
    );
}

function TypeGroupHeaderRow({ label }: { label: string }) {
    return (
        <TableRow className="bg-muted/60 hover:bg-muted/60">
            <TableCell
                colSpan={5}
                className="py-1.5 pl-3 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
            >
                {label}
            </TableCell>
        </TableRow>
    );
}

interface VarianceCellProps {
    actual: number;
    budget: number;
    type: 'income' | 'expense';
}

function VarianceCell({ actual, budget, type }: VarianceCellProps) {
    if (actual === 0 && budget === 0) {
        return <span className="text-muted-foreground">—</span>;
    }

    const diff = actual - budget;

    const favorable = (type === 'income' && diff >= 0) || (type === 'expense' && diff <= 0);
    const unfavorable = (type === 'income' && diff < 0) || (type === 'expense' && diff > 0);

    return (
        <span
            className={cn(
                'tabular-nums',
                favorable && 'font-medium text-emerald-600 dark:text-emerald-400',
                unfavorable && 'font-medium text-destructive',
                !favorable && !unfavorable && 'text-muted-foreground',
            )}
        >
            {diff > 0 ? '+' : ''}
            {formatCurrency(diff)}
        </span>
    );
}

interface GroupTotalsRowProps {
    label: string;
    categories: Category[];
    form: FormState;
    budgetEntries: Record<number, BudgetEntry>;
    type: 'income' | 'expense';
}

function GroupTotalsRow({ label, categories, form, budgetEntries, type }: GroupTotalsRowProps) {
    const actualTotal = categories.reduce((sum, cat) => sum + parseAmount(form[cat.id]?.amount ?? ''), 0);
    const budgetTotal = categories.reduce((sum, cat) => sum + parseAmount(budgetEntries[cat.id]?.amount ?? '0'), 0);

    return (
        <TableRow className="bg-muted/30 font-semibold hover:bg-muted/30">
            <TableCell className="py-1.5 pl-3 text-xs">{label}</TableCell>
            <TableCell className="py-1.5 text-right text-xs tabular-nums">
                {budgetTotal !== 0 ? formatCurrency(budgetTotal) : <span className="text-muted-foreground">—</span>}
            </TableCell>
            <TableCell className="py-1.5 text-right text-xs tabular-nums">
                {actualTotal !== 0 ? formatCurrency(actualTotal) : <span className="text-muted-foreground">—</span>}
            </TableCell>
            <TableCell className="py-1.5 text-right text-xs">
                <VarianceCell actual={actualTotal} budget={budgetTotal} type={type} />
            </TableCell>
            <TableCell />
        </TableRow>
    );
}

export default function ActualIndex({ year, month, categories, entries, budgetEntries }: Props) {
    const [form, setForm] = useState<FormState>(() => buildInitialForm(categories, entries));
    const initialFormRef = useRef<FormState>(buildInitialForm(categories, entries));

    useEffect(() => {
        const initial = buildInitialForm(categories, entries);
        setForm(initial);
        initialFormRef.current = initial;
    }, [year, month, categories, entries]);

    const handleChange = useCallback((catId: number, field: keyof RowState, value: string) => {
        setForm((prev) => ({
            ...prev,
            [catId]: { ...prev[catId], [field]: value },
        }));
    }, []);

    const handleRowBlur = useCallback(
        (catId: number) => {
            const currentRow = form[catId];
            const initialRow = initialFormRef.current[catId];

            if (currentRow?.amount === initialRow?.amount && currentRow?.description === initialRow?.description) {
                return;
            }

            const amount = String(parseAmount(currentRow?.amount ?? ''));
            const existingEntry = entries[catId];

            // Nothing to save if empty and no existing entry
            if (amount === '0' && !existingEntry) return;

            router.post(
                '/actual/bulk',
                {
                    year,
                    month,
                    entries: [
                        {
                            category_id: catId,
                            amount,
                            description: currentRow?.description || null,
                            notes: existingEntry?.notes ?? null,
                        },
                    ],
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        initialFormRef.current[catId] = { ...currentRow };
                        toast.success('Salvato');
                    },
                    onError: () => {
                        toast.error('Errore durante il salvataggio');
                    },
                },
            );
        },
        [form, entries, year, month],
    );

    const navigateMonth = (delta: number) => {
        let newMonth = month + delta;
        let newYear = year;
        if (newMonth < 1) {
            newMonth = 12;
            newYear -= 1;
        } else if (newMonth > 12) {
            newMonth = 1;
            newYear += 1;
        }
        router.get('/actual', { year: newYear, month: newMonth }, { preserveScroll: false });
    };

    const navigateYear = (delta: number) => {
        router.get('/actual', { year: year + delta, month }, { preserveScroll: false });
    };

    const incomeCategories = categories.filter((c) => c.type === 'income').sort((a, b) => a.sort_order - b.sort_order);

    const expenseCategories = categories
        .filter((c) => c.type === 'expense')
        .sort((a, b) => a.sort_order - b.sort_order);

    const allIncomeActual = incomeCategories.reduce((sum, cat) => sum + parseAmount(form[cat.id]?.amount ?? ''), 0);
    const allExpenseActual = expenseCategories.reduce((sum, cat) => sum + parseAmount(form[cat.id]?.amount ?? ''), 0);
    const allIncomeBudget = incomeCategories.reduce(
        (sum, cat) => sum + parseAmount(budgetEntries[cat.id]?.amount ?? '0'),
        0,
    );
    const allExpenseBudget = expenseCategories.reduce(
        (sum, cat) => sum + parseAmount(budgetEntries[cat.id]?.amount ?? '0'),
        0,
    );
    const netActual = allIncomeActual - allExpenseActual;
    const netBudget = allIncomeBudget - allExpenseBudget;

    const monthLabel = formatMonth(month);

    return (
        <AppLayout>
            <Head title="Consuntivo" />

            <div className="space-y-6">
                {/* Page header */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Consuntivo</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Registra le entrate e uscite effettive del mese selezionato.
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

                        {/* Month selector */}
                        <div className="flex items-center gap-1 rounded-lg border bg-card px-1 py-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                onClick={() => navigateMonth(-1)}
                                aria-label="Mese precedente"
                            >
                                <ChevronLeft className="size-4" />
                            </Button>
                            <span className="min-w-20 text-center text-sm font-semibold capitalize">{monthLabel}</span>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                onClick={() => navigateMonth(1)}
                                aria-label="Mese successivo"
                            >
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Summary cards */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <SummaryCard label="Entrate Budget" value={allIncomeBudget} variant="neutral" />
                    <SummaryCard
                        label="Entrate Effettive"
                        value={allIncomeActual}
                        variant={allIncomeActual >= allIncomeBudget ? 'positive' : 'negative'}
                        diff={allIncomeActual - allIncomeBudget}
                        diffType="income"
                    />
                    <SummaryCard label="Uscite Budget" value={allExpenseBudget} variant="neutral" />
                    <SummaryCard
                        label="Uscite Effettive"
                        value={allExpenseActual}
                        variant={allExpenseActual <= allExpenseBudget ? 'positive' : 'negative'}
                        diff={allExpenseActual - allExpenseBudget}
                        diffType="expense"
                    />
                </div>

                {/* Main table */}
                <div className="rounded-lg border bg-card shadow-xs">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow className="bg-muted hover:bg-muted/40">
                                <TableHead className="min-w-44 pl-3 font-semibold">Categoria</TableHead>
                                <TableHead className="min-w-32 text-right font-semibold">Budget</TableHead>
                                <TableHead className="min-w-32 text-right font-semibold">Effettivo</TableHead>
                                <TableHead className="min-w-28 text-right font-semibold">Scostamento</TableHead>
                                <TableHead className="min-w-48 font-semibold">Causale</TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {/* Income group */}
                            <TypeGroupHeaderRow label="Entrate" />
                            {incomeCategories.map((cat) => (
                                <ActualRow
                                    key={cat.id}
                                    category={cat}
                                    rowState={form[cat.id] ?? { amount: '', description: '' }}
                                    budgetEntry={budgetEntries[cat.id]}
                                    onChange={handleChange}
                                    onBlur={handleRowBlur}
                                />
                            ))}
                            {incomeCategories.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={5} className="py-4 text-center text-xs text-muted-foreground">
                                        Nessuna categoria di entrata
                                    </TableCell>
                                </TableRow>
                            )}
                            <GroupTotalsRow
                                label="Totale Entrate"
                                categories={incomeCategories}
                                form={form}
                                budgetEntries={budgetEntries}
                                type="income"
                            />

                            {/* Expense group */}
                            <TypeGroupHeaderRow label="Uscite" />
                            {expenseCategories.map((cat) => (
                                <ActualRow
                                    key={cat.id}
                                    category={cat}
                                    rowState={form[cat.id] ?? { amount: '', description: '' }}
                                    budgetEntry={budgetEntries[cat.id]}
                                    onChange={handleChange}
                                    onBlur={handleRowBlur}
                                />
                            ))}
                            {expenseCategories.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={5} className="py-4 text-center text-xs text-muted-foreground">
                                        Nessuna categoria di uscita
                                    </TableCell>
                                </TableRow>
                            )}
                            <GroupTotalsRow
                                label="Totale Uscite"
                                categories={expenseCategories}
                                form={form}
                                budgetEntries={budgetEntries}
                                type="expense"
                            />
                        </TableBody>

                        <TableFooter>
                            <TableRow className="hover:bg-muted/50">
                                <TableCell className="py-1.5 pl-3 text-xs font-bold">Saldo Netto</TableCell>
                                <TableCell className="py-1.5 text-right text-xs font-bold tabular-nums">
                                    {netBudget !== 0 ? (
                                        <span
                                            className={cn(
                                                netBudget > 0 && 'text-emerald-600 dark:text-emerald-400',
                                                netBudget < 0 && 'text-destructive',
                                            )}
                                        >
                                            {formatCurrency(netBudget)}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )}
                                </TableCell>
                                <TableCell className="py-1.5 text-right text-xs font-bold tabular-nums">
                                    {netActual !== 0 ? (
                                        <span
                                            className={cn(
                                                netActual > 0 && 'text-emerald-600 dark:text-emerald-400',
                                                netActual < 0 && 'text-destructive',
                                            )}
                                        >
                                            {formatCurrency(netActual)}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">—</span>
                                    )}
                                </TableCell>
                                <TableCell className="py-1.5 text-right text-xs font-bold">
                                    <VarianceCell actual={netActual} budget={netBudget} type="income" />
                                </TableCell>
                                <TableCell />
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}

// ─── Sub-components ──────────────────────────────────────────────────────────

interface ActualRowProps {
    category: Category;
    rowState: RowState;
    budgetEntry: BudgetEntry | undefined;
    onChange: (catId: number, field: keyof RowState, value: string) => void;
    onBlur: (catId: number) => void;
}

function ActualRow({ category, rowState, budgetEntry, onChange, onBlur }: ActualRowProps) {
    const budgetAmount = parseAmount(budgetEntry?.amount ?? '0');
    const actualAmount = parseAmount(rowState.amount);

    return (
        <TableRow className="group">
            <TableCell className="py-1.5 pl-3">
                <div className="flex items-center">
                    <CategoryColorDot color={category.color} />
                    <span className="text-xs font-medium">{category.name}</span>
                </div>
            </TableCell>

            {/* Budget reference (read-only) */}
            <TableCell className="py-1.5 text-right">
                <span className="text-xs text-muted-foreground tabular-nums">
                    {budgetAmount !== 0 ? formatCurrency(budgetAmount) : <span>—</span>}
                </span>
            </TableCell>

            {/* Actual amount input */}
            <TableCell className="p-1">
                <Input
                    type="text"
                    inputMode="decimal"
                    value={rowState.amount}
                    onChange={(e) => onChange(category.id, 'amount', e.target.value)}
                    onBlur={() => onBlur(category.id)}
                    onFocus={(e) => e.target.select()}
                    className="h-7 text-right text-xs tabular-nums"
                    placeholder="0,00"
                    aria-label={`Effettivo ${category.name}`}
                />
            </TableCell>

            {/* Variance */}
            <TableCell className="py-1.5 text-right text-xs">
                <VarianceCell actual={actualAmount} budget={budgetAmount} type={category.type} />
            </TableCell>

            {/* Description / Causale */}
            <TableCell className="p-1">
                <Input
                    type="text"
                    value={rowState.description}
                    onChange={(e) => onChange(category.id, 'description', e.target.value)}
                    onBlur={() => onBlur(category.id)}
                    className="h-7 text-xs"
                    placeholder="Causale..."
                    aria-label={`Causale ${category.name}`}
                />
            </TableCell>
        </TableRow>
    );
}

interface SummaryCardProps {
    label: string;
    value: number;
    variant: 'positive' | 'negative' | 'neutral';
    diff?: number;
    diffType?: 'income' | 'expense';
}

function SummaryCard({ label, value, variant, diff, diffType }: SummaryCardProps) {
    const showDiff = diff !== undefined && diffType !== undefined && value !== 0;
    const favorable = showDiff && ((diffType === 'income' && diff >= 0) || (diffType === 'expense' && diff <= 0));

    return (
        <div className="rounded-lg border bg-card p-4 shadow-xs">
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
            <p
                className={cn(
                    'mt-1 text-xl font-bold tracking-tight tabular-nums',
                    variant === 'positive' && 'text-emerald-600 dark:text-emerald-400',
                    variant === 'negative' && 'text-destructive',
                    variant === 'neutral' && 'text-foreground',
                )}
            >
                {formatCurrency(value)}
            </p>
            {showDiff && diff !== 0 && (
                <p
                    className={cn(
                        'mt-0.5 text-xs font-medium tabular-nums',
                        favorable ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive',
                    )}
                >
                    {diff > 0 ? '+' : ''}
                    {formatCurrency(diff)}
                </p>
            )}
        </div>
    );
}
