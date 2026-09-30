import { ArrowDownUp } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
    type CategoryKind,
    formatPercent,
    TONE_CLASSES,
    TONE_LABEL,
    TONE_TEXT,
    type VarianceTone,
    varianceTone,
} from '@/lib/actual-variance';
import { cn, formatCurrency, formatMonth } from '@/lib/utils';
import type { ActualCategory, MonthCell, VarianceReport, VarianceRow } from './types';

type SortKey = 'variance_pct' | 'variance' | 'avg_actual' | 'name';

interface YearViewProps {
    year: number;
    categories: ActualCategory[];
    report: VarianceReport;
    onOpenMonth: (month: number, categoryId: number) => void;
}

const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1);
const LEGEND: VarianceTone[] = ['good', 'ok', 'warn', 'bad', 'unbudgeted'];
const COLUMNS = 6 + MONTHS.length;

const compactFormat = new Intl.NumberFormat('it-IT', { maximumFractionDigits: 0, useGrouping: 'always' });

function shortMonth(month: number): string {
    return formatMonth(month).slice(0, 3);
}

/** Higher = worse than budget (overspending, or earning less), so descending order puts problems first. */
function badness(row: VarianceRow, key: Exclude<SortKey, 'name'>): number {
    if (key === 'avg_actual') {
        return row.avg_actual ?? -Infinity;
    }
    const sign = row.type === 'expense' ? 1 : -1;
    if (key === 'variance') {
        return row.variance === null ? -Infinity : sign * row.variance;
    }
    if (row.variance_pct !== null) {
        return sign * row.variance_pct;
    }
    // No budget: unplanned spending is the worst case, anything else goes last.
    return row.type === 'expense' && (row.variance ?? 0) > 0 ? Infinity : -Infinity;
}

function Cell({
    cell,
    type,
    label,
    onClick,
}: {
    cell: MonthCell;
    type: CategoryKind;
    label: string;
    onClick?: () => void;
}) {
    const tone: VarianceTone = cell.status === 'future' ? 'none' : varianceTone(cell.actual, cell.budget, type);
    const shown = cell.status === 'future' ? cell.budget : cell.actual;
    const content = shown !== 0 || cell.budget !== 0 ? compactFormat.format(shown) : '';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    onClick={onClick}
                    disabled={!onClick}
                    className={cn(
                        'h-7 w-full rounded px-1 text-right text-[11px] tabular-nums',
                        TONE_CLASSES[tone],
                        cell.status === 'future' && 'text-muted-foreground/60 italic',
                        cell.status === 'current' && 'border border-dashed border-muted-foreground/60',
                        onClick && 'cursor-pointer hover:ring-1 hover:ring-ring',
                    )}
                >
                    {content}
                </button>
            </TooltipTrigger>
            <TooltipContent>
                <div className="space-y-0.5">
                    <div>Budget: {formatCurrency(cell.budget)}</div>
                    <div>Effettivo: {formatCurrency(cell.actual)}</div>
                    <div>Differenza: {formatCurrency(cell.actual - cell.budget)}</div>
                    {cell.status === 'current' && <div className="italic">Mese in corso, escluso dalle medie</div>}
                    {cell.status === 'future' && <div className="italic">Mese futuro: mostrato il budget</div>}
                    {tone === 'unbudgeted' && <div>Senza budget</div>}
                </div>
            </TooltipContent>
        </Tooltip>
    );
}

export function YearView({ year, categories, report, onOpenMonth }: YearViewProps) {
    const [sort, setSort] = useState<SortKey>('variance_pct');
    const byId = useMemo(() => new Map(categories.map((c) => [c.id, c])), [categories]);

    const sortedRows = (type: CategoryKind) =>
        report.rows
            .filter((r) => r.type === type && r.category_id !== null && byId.has(r.category_id))
            .filter((r) => r.months.some((m) => m.budget !== 0 || m.actual !== 0))
            .sort((a, b) => {
                const nameA = byId.get(a.category_id!)!.name;
                const nameB = byId.get(b.category_id!)!.name;
                if (sort === 'name') {
                    return nameA.localeCompare(nameB);
                }
                const diff = badness(b, sort) - badness(a, sort);
                return Number.isNaN(diff) || diff === 0 ? (b.avg_actual ?? 0) - (a.avg_actual ?? 0) : diff;
            });

    function sortButton(key: SortKey, label: string) {
        return (
            <button
                type="button"
                onClick={() => setSort(key)}
                className={cn('inline-flex items-center gap-1', sort === key && 'text-foreground underline')}
            >
                {label}
                {key !== 'name' && <ArrowDownUp className="size-3 opacity-50" />}
            </button>
        );
    }

    function summaryCells(row: VarianceRow) {
        const tone: VarianceTone =
            row.avg_budget === null || row.avg_actual === null
                ? 'none'
                : varianceTone(row.avg_actual, row.avg_budget, row.type);
        return (
            <>
                <TableCell className="text-right text-xs text-muted-foreground tabular-nums">
                    {row.avg_budget !== null ? formatCurrency(row.avg_budget) : '—'}
                </TableCell>
                <TableCell className="text-right text-xs font-medium tabular-nums">
                    {row.avg_actual !== null ? formatCurrency(row.avg_actual) : '—'}
                </TableCell>
                <TableCell className={cn('text-right text-xs font-medium tabular-nums', TONE_TEXT[tone])}>
                    {row.variance !== null ? `${row.variance > 0 ? '+' : ''}${formatCurrency(row.variance)}` : '—'}
                </TableCell>
                <TableCell className={cn('text-right text-xs font-semibold tabular-nums', TONE_TEXT[tone])}>
                    {tone === 'unbudgeted' ? 'senza budget' : formatPercent(row.variance_pct)}
                </TableCell>
                <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                    {row.peak ? `${shortMonth(row.peak.month)} ${compactFormat.format(row.peak.actual)}` : '—'}
                </TableCell>
            </>
        );
    }

    function totalRow(label: string, row: VarianceRow) {
        return (
            <TableRow className="bg-muted/30 font-semibold hover:bg-muted/30">
                <TableCell className="sticky left-0 bg-muted py-1.5 pl-3 text-xs">{label}</TableCell>
                {summaryCells(row)}
                {row.months.map((cell) => (
                    <TableCell key={cell.month} className="p-0.5">
                        <Cell cell={cell} type={row.type} label={`${label} ${formatMonth(cell.month)}`} />
                    </TableCell>
                ))}
            </TableRow>
        );
    }

    const groups: { type: CategoryKind; label: string; total: VarianceRow }[] = [
        { type: 'income', label: 'Entrate', total: report.totals.income },
        { type: 'expense', label: 'Uscite', total: report.totals.expense },
    ];

    return (
        <TooltipProvider delayDuration={200}>
            <div className="space-y-3">
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground">
                    <span>
                        {report.closed_months > 0
                            ? `Medie su ${report.closed_months} ${report.closed_months === 1 ? 'mese chiuso' : 'mesi chiusi'} del ${year}.`
                            : `Nessun mese chiuso nel ${year}: medie non disponibili.`}
                    </span>
                    {LEGEND.map((tone) => (
                        <span key={tone} className="inline-flex items-center gap-1">
                            <span className={cn('inline-block size-3 rounded', TONE_CLASSES[tone])} />
                            {TONE_LABEL[tone]}
                        </span>
                    ))}
                </div>
                <div className="overflow-x-auto rounded-lg border bg-card shadow-xs">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow className="bg-muted hover:bg-muted">
                                <TableHead className="sticky left-0 z-10 min-w-44 bg-muted pl-3 font-semibold">
                                    {sortButton('name', 'Categoria')}
                                </TableHead>
                                <TableHead className="text-right font-semibold">Budget/mese</TableHead>
                                <TableHead className="text-right font-semibold">
                                    {sortButton('avg_actual', 'Media eff.')}
                                </TableHead>
                                <TableHead className="text-right font-semibold">
                                    {sortButton('variance', 'Scost. €')}
                                </TableHead>
                                <TableHead className="text-right font-semibold">
                                    {sortButton('variance_pct', 'Scost. %')}
                                </TableHead>
                                <TableHead className="font-semibold">Picco</TableHead>
                                {MONTHS.map((m) => (
                                    <TableHead key={m} className="min-w-14 text-center font-semibold capitalize">
                                        {shortMonth(m)}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {groups.map(({ type, label, total }) => (
                                <Fragment key={type}>
                                    <TableRow className="bg-muted/60 hover:bg-muted/60">
                                        <TableCell
                                            colSpan={COLUMNS}
                                            className="py-1.5 pl-3 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                                        >
                                            {label}
                                        </TableCell>
                                    </TableRow>
                                    {sortedRows(type).map((row) => {
                                        const category = byId.get(row.category_id!)!;
                                        return (
                                            <TableRow key={category.id} data-category-id={category.id}>
                                                <TableCell className="sticky left-0 z-10 bg-card py-1 pl-3">
                                                    <div className="flex items-center gap-2">
                                                        <span
                                                            className="size-2.5 shrink-0 rounded-full"
                                                            style={{ backgroundColor: category.color ?? 'transparent' }}
                                                            aria-hidden="true"
                                                        />
                                                        <span className="text-xs font-medium">{category.name}</span>
                                                    </div>
                                                </TableCell>
                                                {summaryCells(row)}
                                                {row.months.map((cell) => (
                                                    <TableCell key={cell.month} className="p-0.5">
                                                        <Cell
                                                            cell={cell}
                                                            type={row.type}
                                                            label={`${category.name} ${formatMonth(cell.month)}`}
                                                            onClick={
                                                                cell.status === 'future'
                                                                    ? undefined
                                                                    : () => onOpenMonth(cell.month, category.id)
                                                            }
                                                        />
                                                    </TableCell>
                                                ))}
                                            </TableRow>
                                        );
                                    })}
                                    {totalRow(`Totale ${label}`, total)}
                                </Fragment>
                            ))}
                            {totalRow('Saldo netto', report.totals.net)}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </TooltipProvider>
    );
}
