import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { actualOf, MonthView } from '@/components/actual/month-view';
import type { ActualCategory, ActualLine, VarianceReport } from '@/components/actual/types';
import { YearView } from '@/components/actual/year-view';
import { BankImportDialog } from '@/components/shared/bank-import-dialog';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { saveActualView, viewToRestore } from '@/lib/actual-view-preference';
import { cn, formatCurrency, formatMonth } from '@/lib/utils';

type Tab = 'month' | 'year';

interface Props {
    tab: Tab;
    year: number;
    month: number;
    expandedCategoryId: number | null;
    categories: ActualCategory[];
    budgets: Record<number, number>;
    lines: Record<number, ActualLine[]>;
    report: VarianceReport;
}

function visit(params: { tab: Tab; year: number; month: number; category?: number }) {
    router.get('/actual', params, { preserveScroll: params.category === undefined });
}

interface StepperProps {
    label: string;
    prevLabel: string;
    nextLabel: string;
    onPrev: () => void;
    onNext: () => void;
    wide?: boolean;
}

function Stepper({ label, prevLabel, nextLabel, onPrev, onNext, wide }: StepperProps) {
    return (
        <div className="flex items-center gap-1 rounded-lg border bg-card px-1 py-1">
            <Button variant="ghost" size="icon" className="size-8" onClick={onPrev} aria-label={prevLabel}>
                <ChevronLeft className="size-4" />
            </Button>
            <span
                className={cn(
                    'text-center text-sm font-semibold capitalize tabular-nums',
                    wide ? 'min-w-20' : 'min-w-12',
                )}
            >
                {label}
            </span>
            <Button variant="ghost" size="icon" className="size-8" onClick={onNext} aria-label={nextLabel}>
                <ChevronRight className="size-4" />
            </Button>
        </div>
    );
}

export default function ActualIndex({
    tab,
    year,
    month,
    expandedCategoryId,
    categories,
    budgets,
    lines,
    report,
}: Props) {
    const restoring = useRef(false);

    useEffect(() => {
        const saved = viewToRestore(window.location.search, { tab, year, month });
        if (saved !== null) {
            restoring.current = true;
            router.get('/actual', { ...saved }, { replace: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- restore only on page entry
    }, []);

    useEffect(() => {
        if (restoring.current) {
            restoring.current = false;
            return;
        }
        saveActualView({ tab, year, month });
    }, [tab, year, month]);

    const shiftMonth = (delta: number) => {
        const index = year * 12 + (month - 1) + delta;
        visit({ tab, year: Math.floor(index / 12), month: (index % 12) + 1 });
    };

    const budgetOf = (type: 'income' | 'expense') =>
        categories.filter((c) => c.type === type).reduce((sum, c) => sum + (budgets[c.id] ?? 0), 0);
    const actualOfType = (type: 'income' | 'expense') =>
        categories.filter((c) => c.type === type).reduce((sum, c) => sum + actualOf(lines[c.id]), 0);

    const incomeBudget = budgetOf('income');
    const incomeActual = actualOfType('income');
    const expenseBudget = budgetOf('expense');
    const expenseActual = actualOfType('expense');

    return (
        <AppLayout>
            <Head title="Consuntivo" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Consuntivo</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {tab === 'month'
                                ? 'Voci del mese per categoria, confrontate con il budget.'
                                : 'Scostamenti tra budget e consuntivo per categoria, mese per mese.'}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <Tabs value={tab} onValueChange={(value) => visit({ tab: value as Tab, year, month })}>
                            <TabsList>
                                <TabsTrigger value="month">Mese</TabsTrigger>
                                <TabsTrigger value="year">Anno</TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <BankImportDialog />
                        <Stepper
                            label={String(year)}
                            prevLabel="Anno precedente"
                            nextLabel="Anno successivo"
                            onPrev={() => visit({ tab, year: year - 1, month })}
                            onNext={() => visit({ tab, year: year + 1, month })}
                        />
                        {tab === 'month' && (
                            <Stepper
                                label={formatMonth(month)}
                                prevLabel="Mese precedente"
                                nextLabel="Mese successivo"
                                onPrev={() => shiftMonth(-1)}
                                onNext={() => shiftMonth(1)}
                                wide
                            />
                        )}
                    </div>
                </div>

                {tab === 'month' ? (
                    <>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <SummaryCard label="Entrate Budget" value={incomeBudget} variant="neutral" />
                            <SummaryCard
                                label="Entrate Effettive"
                                value={incomeActual}
                                variant={incomeActual >= incomeBudget ? 'positive' : 'negative'}
                                diff={incomeActual - incomeBudget}
                                diffType="income"
                            />
                            <SummaryCard label="Uscite Budget" value={expenseBudget} variant="neutral" />
                            <SummaryCard
                                label="Uscite Effettive"
                                value={expenseActual}
                                variant={expenseActual <= expenseBudget ? 'positive' : 'negative'}
                                diff={expenseActual - expenseBudget}
                                diffType="expense"
                            />
                        </div>
                        <MonthView
                            key={`${year}-${month}`}
                            year={year}
                            month={month}
                            categories={categories}
                            budgets={budgets}
                            lines={lines}
                            expandedCategoryId={expandedCategoryId}
                        />
                    </>
                ) : (
                    <YearView
                        year={year}
                        categories={categories}
                        report={report}
                        onOpenMonth={(m, categoryId) => visit({ tab: 'month', year, month: m, category: categoryId })}
                    />
                )}
            </div>
        </AppLayout>
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
