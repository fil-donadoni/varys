import { router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Plus } from 'lucide-react';
import { Fragment, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type CategoryKind, formatPercent, TONE_TEXT, varianceRatio, varianceTone } from '@/lib/actual-variance';
import { cn, formatCurrency } from '@/lib/utils';
import { LineRow } from './line-row';
import { ManualItemForm, type ManualItemValues } from './manual-item-form';
import type { ActualCategory, ActualLine } from './types';

interface MonthViewProps {
    year: number;
    month: number;
    categories: ActualCategory[];
    budgets: Record<number, number>;
    lines: Record<number, ActualLine[]>;
    expandedCategoryId: number | null;
}

const COLUMNS = 6;
// Usage bar is full at 150% of budget.
const BAR_CAP = 1.5;

const GROUPS: { type: CategoryKind; label: string }[] = [
    { type: 'income', label: 'Entrate' },
    { type: 'expense', label: 'Uscite' },
];

export function actualOf(lines: ActualLine[] | undefined): number {
    return (lines ?? []).reduce((sum, line) => sum + line.amount, 0);
}

function defaultDate(year: number, month: number): string {
    const today = new Date();
    const day = today.getFullYear() === year && today.getMonth() + 1 === month ? today.getDate() : 1;
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function signed(amount: number): string {
    return `${amount > 0 ? '+' : ''}${formatCurrency(amount)}`;
}

export function MonthView({ year, month, categories, budgets, lines, expandedCategoryId }: MonthViewProps) {
    const [expanded, setExpanded] = useState<Set<number>>(
        () => new Set(expandedCategoryId ? [expandedCategoryId] : []),
    );
    const [adding, setAdding] = useState<number | null>(null);
    const [showEmpty, setShowEmpty] = useState(false);

    const toggle = (id: number) =>
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });

    function addItem(categoryId: number, values: ManualItemValues) {
        router.post(
            '/actual-items',
            { ...values, category_id: categoryId },
            {
                preserveScroll: true,
                onSuccess: () => setAdding(null),
                onError: () => toast.error('Voce non salvata'),
            },
        );
    }

    const isEmpty = (c: ActualCategory) => (budgets[c.id] ?? 0) === 0 && (lines[c.id]?.length ?? 0) === 0;
    const emptyCategories = categories.filter(isEmpty);
    const totalOf = (type: CategoryKind) => {
        const ofType = categories.filter((c) => c.type === type);
        return {
            budget: ofType.reduce((sum, c) => sum + (budgets[c.id] ?? 0), 0),
            actual: ofType.reduce((sum, c) => sum + actualOf(lines[c.id]), 0),
        };
    };
    const income = totalOf('income');
    const expense = totalOf('expense');

    function categoryRows(category: ActualCategory) {
        const budget = budgets[category.id] ?? 0;
        const categoryLines = lines[category.id] ?? [];
        const actual = actualOf(categoryLines);
        const tone = varianceTone(actual, budget, category.type);
        const open = expanded.has(category.id);
        const usage = budget > 0 ? Math.min(actual / budget, BAR_CAP) : actual > 0 ? BAR_CAP : 0;

        return (
            <Fragment key={category.id}>
                <TableRow className="cursor-pointer" onClick={() => toggle(category.id)} aria-expanded={open}>
                    <TableCell className="py-1.5 pl-3">
                        <div className="flex items-center gap-2">
                            {open ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                            <span
                                className="size-2.5 shrink-0 rounded-full"
                                style={{ backgroundColor: category.color ?? 'transparent' }}
                                aria-hidden="true"
                            />
                            <span className="text-xs font-medium">{category.name}</span>
                            {categoryLines.length > 0 && (
                                <span className="text-[10px] text-muted-foreground">
                                    {categoryLines.length} {categoryLines.length === 1 ? 'voce' : 'voci'}
                                </span>
                            )}
                        </div>
                        <div className="mt-1 ml-9 h-1 max-w-48 rounded-full bg-muted" aria-hidden="true">
                            <div
                                className={cn(
                                    'h-1 rounded-full',
                                    tone === 'bad' || tone === 'unbudgeted'
                                        ? 'bg-destructive'
                                        : tone === 'warn'
                                          ? 'bg-amber-500'
                                          : 'bg-emerald-500',
                                )}
                                style={{ width: `${(Math.max(usage, 0) / BAR_CAP) * 100}%` }}
                            />
                        </div>
                    </TableCell>
                    <TableCell className="text-right text-xs text-muted-foreground tabular-nums">
                        {budget !== 0 ? formatCurrency(budget) : '—'}
                    </TableCell>
                    <TableCell className="text-right text-xs font-medium tabular-nums">
                        {actual !== 0 ? formatCurrency(actual) : '—'}
                    </TableCell>
                    <TableCell className={cn('text-right text-xs font-medium tabular-nums', TONE_TEXT[tone])}>
                        {actual !== 0 || budget !== 0 ? signed(actual - budget) : '—'}
                    </TableCell>
                    <TableCell className={cn('text-right text-xs tabular-nums', TONE_TEXT[tone])}>
                        {tone === 'unbudgeted' ? 'senza budget' : formatPercent(varianceRatio(actual, budget))}
                    </TableCell>
                    <TableCell />
                </TableRow>
                {open && (
                    <TableRow className="hover:bg-transparent">
                        <TableCell colSpan={COLUMNS} className="bg-muted/20 py-1">
                            <ul className="divide-y">
                                {categoryLines.map((line) => (
                                    <LineRow key={line.key} line={line} category={category} categories={categories} />
                                ))}
                                {categoryLines.length === 0 && (
                                    <li className="py-1 pl-8 text-xs text-muted-foreground">Nessuna voce nel mese.</li>
                                )}
                                <li className="pl-8">
                                    {adding === category.id ? (
                                        <ManualItemForm
                                            initial={{ date: defaultDate(year, month), description: '', amount: '' }}
                                            onSubmit={(values) => addItem(category.id, values)}
                                            onCancel={() => setAdding(null)}
                                        />
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() => setAdding(category.id)}
                                        >
                                            <Plus className="size-3.5" /> Aggiungi voce
                                        </Button>
                                    )}
                                </li>
                            </ul>
                        </TableCell>
                    </TableRow>
                )}
            </Fragment>
        );
    }

    function totalRow(label: string, budget: number, actual: number, type: CategoryKind) {
        const tone = varianceTone(actual, budget, type);
        return (
            <TableRow className="bg-muted/30 font-semibold hover:bg-muted/30">
                <TableCell className="py-1.5 pl-3 text-xs">{label}</TableCell>
                <TableCell className="text-right text-xs tabular-nums">{formatCurrency(budget)}</TableCell>
                <TableCell className="text-right text-xs tabular-nums">{formatCurrency(actual)}</TableCell>
                <TableCell className={cn('text-right text-xs tabular-nums', TONE_TEXT[tone])}>
                    {signed(actual - budget)}
                </TableCell>
                <TableCell className={cn('text-right text-xs tabular-nums', TONE_TEXT[tone])}>
                    {formatPercent(varianceRatio(actual, budget))}
                </TableCell>
                <TableCell />
            </TableRow>
        );
    }

    return (
        <div className="rounded-lg border bg-card shadow-xs">
            <Table className="text-xs">
                <TableHeader>
                    <TableRow className="bg-muted hover:bg-muted/40">
                        <TableHead className="min-w-64 pl-3 font-semibold">Categoria</TableHead>
                        <TableHead className="text-right font-semibold">Budget</TableHead>
                        <TableHead className="text-right font-semibold">Effettivo</TableHead>
                        <TableHead className="text-right font-semibold">Scostamento</TableHead>
                        <TableHead className="text-right font-semibold">%</TableHead>
                        <TableHead className="w-0" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {GROUPS.map(({ type, label }) => {
                        const total = type === 'income' ? income : expense;
                        return (
                            <Fragment key={type}>
                                <TableRow className="bg-muted/60 hover:bg-muted/60">
                                    <TableCell
                                        colSpan={COLUMNS}
                                        className="py-1.5 pl-3 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                                    >
                                        {label}
                                    </TableCell>
                                </TableRow>
                                {categories.filter((c) => c.type === type && !isEmpty(c)).map(categoryRows)}
                                {totalRow(`Totale ${label}`, total.budget, total.actual, type)}
                            </Fragment>
                        );
                    })}
                    {emptyCategories.length > 0 && (
                        <>
                            <TableRow className="cursor-pointer" onClick={() => setShowEmpty(!showEmpty)}>
                                <TableCell colSpan={COLUMNS} className="py-1.5 pl-3 text-xs text-muted-foreground">
                                    {showEmpty ? '▾' : '▸'} {emptyCategories.length} categorie vuote
                                </TableCell>
                            </TableRow>
                            {showEmpty && emptyCategories.map(categoryRows)}
                        </>
                    )}
                </TableBody>
                <TableFooter>
                    {totalRow('Saldo Netto', income.budget - expense.budget, income.actual - expense.actual, 'income')}
                </TableFooter>
            </Table>
        </div>
    );
}
