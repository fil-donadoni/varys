import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';
import { Fragment, useState } from 'react';
import { toast } from 'sonner';
import { CategorySelect } from '@/components/shared/category-select';
import { ImportPipeline, type Pipeline } from '@/components/shared/import-pipeline';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { groupByMerchant, type ImportCategory, type ImportTransaction, type MerchantGroup } from '@/lib/bank-import';
import { cn, formatCurrency } from '@/lib/utils';

interface BankImportSummary {
    id: number;
    bank: string;
    original_filename: string;
    period_start: string | null;
    period_end: string | null;
    rows_total: number;
    rows_imported: number;
    rows_duplicates: number;
    status: 'review' | 'completed';
}

interface Props {
    bankImport: BankImportSummary;
    transactions: ImportTransaction[];
    categories: ImportCategory[];
    pipeline: Pipeline;
}

function formatDate(date: string | null): string {
    return date ? new Date(date).toLocaleDateString('it-IT') : '—';
}

function assign(transaction: ImportTransaction, categoryId: number | null, exclude: boolean, applyToMerchant: boolean) {
    router.patch(
        `/bank-transactions/${transaction.id}`,
        { category_id: categoryId, exclude, apply_to_merchant: applyToMerchant },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => toast.error('Errore durante il salvataggio'),
        },
    );
}

export default function BankImportShow({ bankImport, transactions, categories, pipeline }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const readOnly = bankImport.status === 'completed';

    const toReview = transactions.filter((t) => t.status === 'to_review');

    // The review queue is frozen until the page reloads or the AI runs: rows that get a category stay in place
    // (marked as done) instead of disappearing, so the list never shifts under the cursor.
    const queueKey = pipeline.llm.ran_at ?? 'initial';
    const [queue, setQueue] = useState(() => ({ key: queueKey, ids: new Set(toReview.map((t) => t.id)) }));
    if (queue.key !== queueKey) {
        setQueue({ key: queueKey, ids: new Set(toReview.map((t) => t.id)) });
    }
    const reviewList = transactions.filter((t) => queue.ids.has(t.id));
    const ready = transactions.filter((t) => t.status === 'auto' || t.status === 'confirmed');
    const excluded = transactions.filter((t) => t.status === 'excluded');

    function complete() {
        router.post(
            `/bank-imports/${bankImport.id}/complete`,
            {},
            { onSuccess: () => toast.success('Consuntivi aggiornati') },
        );
    }

    return (
        <AppLayout>
            <Head title="Revisione import" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <Link
                            href="/bank-imports"
                            className="mb-1 inline-flex items-center text-xs text-muted-foreground hover:text-foreground"
                        >
                            <ChevronLeft className="size-3" /> Storico import
                        </Link>
                        <h1 className="text-2xl font-bold tracking-tight">
                            {bankImport.bank} · {formatDate(bankImport.period_start)} –{' '}
                            {formatDate(bankImport.period_end)}
                        </h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {bankImport.original_filename} · {bankImport.rows_imported} movimenti importati
                            {bankImport.rows_duplicates > 0 && `, ${bankImport.rows_duplicates} già presenti e saltati`}
                        </p>
                    </div>

                    {!readOnly && (
                        <div className="flex flex-col items-end gap-1">
                            <Button onClick={complete} disabled={toReview.length > 0}>
                                Conferma import
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                {toReview.length > 0
                                    ? `Mancano ${toReview.length} movimenti da confermare`
                                    : 'Tutti i movimenti hanno una categoria'}
                            </p>
                        </div>
                    )}
                </div>

                <div className="rounded-lg border bg-card p-4 shadow-xs">
                    <ImportPipeline
                        importId={bankImport.id}
                        filename={bankImport.original_filename}
                        rowsTotal={bankImport.rows_total}
                        rowsDuplicates={bankImport.rows_duplicates}
                        toReviewCount={toReview.length}
                        readOnly={readOnly}
                        pipeline={pipeline}
                        llmError={errors.llm}
                    />
                </div>

                {errors.import && <p className="text-sm text-destructive">{errors.import}</p>}

                <Tabs defaultValue={toReview.length > 0 ? 'to_review' : 'ready'}>
                    <TabsList>
                        <TabsTrigger value="to_review">Da confermare ({toReview.length})</TabsTrigger>
                        <TabsTrigger value="ready">
                            {readOnly ? 'Confermati' : 'Pronti'} ({ready.length})
                        </TabsTrigger>
                        <TabsTrigger value="excluded">Esclusi ({excluded.length})</TabsTrigger>
                    </TabsList>

                    <TabsContent value="to_review">
                        <MerchantTable transactions={reviewList} categories={categories} readOnly={readOnly} />
                    </TabsContent>
                    <TabsContent value="ready">
                        <MerchantTable transactions={ready} categories={categories} readOnly={readOnly} />
                    </TabsContent>
                    <TabsContent value="excluded">
                        <MerchantTable transactions={excluded} categories={categories} readOnly={readOnly} />
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}

// ─── Sub-components ──────────────────────────────────────────────────────────

interface MerchantTableProps {
    transactions: ImportTransaction[];
    categories: ImportCategory[];
    readOnly: boolean;
}

function MerchantTable({ transactions, categories, readOnly }: MerchantTableProps) {
    const groups = groupByMerchant(transactions);

    if (groups.length === 0) {
        return <p className="py-8 text-center text-sm text-muted-foreground">Nessun movimento</p>;
    }

    return (
        <div className="rounded-lg border bg-card shadow-xs">
            <Table className="text-xs">
                <TableHeader>
                    <TableRow className="bg-muted hover:bg-muted/40">
                        <TableHead className="w-8" />
                        <TableHead className="font-semibold">Esercente / controparte</TableHead>
                        <TableHead className="font-semibold">Tipo</TableHead>
                        <TableHead className="text-right font-semibold">Movimenti</TableHead>
                        <TableHead className="text-right font-semibold">Totale</TableHead>
                        <TableHead className="font-semibold">Categoria</TableHead>
                        <TableHead className="font-semibold">Origine</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {groups.map((group) => (
                        <MerchantRows
                            key={group.merchantKey}
                            group={group}
                            categories={categories}
                            readOnly={readOnly}
                        />
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function MerchantRows({
    group,
    categories,
    readOnly,
}: {
    group: MerchantGroup;
    categories: ImportCategory[];
    readOnly: boolean;
}) {
    const [open, setOpen] = useState(false);
    const first = group.transactions[0];
    const allExcluded = group.transactions.every((t) => t.status === 'excluded');
    const sources = [...new Set(group.transactions.map((t) => t.source_label).filter(Boolean))];
    const resolved = group.transactions.every((t) => t.status !== 'to_review');

    return (
        <Fragment>
            <TableRow className={cn(open && 'bg-muted/30', resolved && 'opacity-50')}>
                <TableCell className="p-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-6"
                        onClick={() => setOpen(!open)}
                        aria-label={open ? 'Nascondi movimenti' : 'Mostra movimenti'}
                    >
                        {open ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                    </Button>
                </TableCell>
                <TableCell>
                    <div className="flex items-center gap-1 font-medium">
                        {resolved && <Check className="size-3.5 text-emerald-600" aria-label="Fatto" />}
                        {group.merchantKey}
                    </div>
                    {group.label.toUpperCase() !== group.merchantKey && (
                        <div className="text-[10px] text-muted-foreground">{group.label}</div>
                    )}
                </TableCell>
                <TableCell className="text-muted-foreground">{group.kindLabel}</TableCell>
                <TableCell className="text-right tabular-nums">{group.transactions.length}</TableCell>
                <TableCell
                    className={cn(
                        'text-right font-medium tabular-nums',
                        group.total > 0 && 'text-emerald-600 dark:text-emerald-400',
                    )}
                >
                    {formatCurrency(group.total)}
                </TableCell>
                <TableCell className="p-1">
                    {group.mixed ? (
                        <span className="text-muted-foreground">Categorie diverse: apri il dettaglio</span>
                    ) : (
                        <CategorySelect
                            categories={categories}
                            amount={group.total}
                            value={group.categoryId}
                            excluded={allExcluded}
                            disabled={readOnly}
                            ariaLabel={`Categoria ${group.merchantKey}`}
                            onChange={(categoryId, exclude) => assign(first, categoryId, exclude, true)}
                        />
                    )}
                </TableCell>
                <TableCell>
                    {sources.map((source) => (
                        <Badge key={source} variant="outline" className="mr-1 text-[10px]">
                            {source}
                        </Badge>
                    ))}
                </TableCell>
            </TableRow>

            {open &&
                group.transactions.map((transaction) => (
                    <TableRow key={transaction.id} className="bg-muted/20 text-[11px]">
                        <TableCell />
                        <TableCell colSpan={2} className="max-w-md">
                            <div className="tabular-nums">
                                {formatDate(transaction.accounting_date)}
                                {transaction.operation_date !== transaction.accounting_date &&
                                    ` (operazione ${formatDate(transaction.operation_date)})`}
                                {transaction.payment_instrument && ` · ${transaction.payment_instrument}`}
                                {transaction.bank_category && ` · banca: ${transaction.bank_category}`}
                            </div>
                            <div className="truncate text-muted-foreground" title={transaction.raw_description}>
                                {transaction.raw_description}
                            </div>
                        </TableCell>
                        <TableCell />
                        <TableCell className="text-right tabular-nums">{formatCurrency(transaction.amount)}</TableCell>
                        <TableCell className="p-1">
                            <CategorySelect
                                categories={categories}
                                amount={transaction.amount}
                                value={transaction.category_id}
                                excluded={transaction.status === 'excluded'}
                                disabled={readOnly}
                                ariaLabel={`Categoria movimento del ${formatDate(transaction.accounting_date)}`}
                                onChange={(categoryId, exclude) => assign(transaction, categoryId, exclude, false)}
                            />
                        </TableCell>
                        <TableCell>
                            {transaction.source_label && (
                                <span className="text-muted-foreground">
                                    {transaction.source_label}
                                    {transaction.confidence !== null && ` ${Math.round(transaction.confidence * 100)}%`}
                                </span>
                            )}
                        </TableCell>
                    </TableRow>
                ))}
        </Fragment>
    );
}
