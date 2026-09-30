import { router } from '@inertiajs/react';
import { CheckCircle2, Circle, CircleDot, Loader2, MinusCircle, ShieldCheck, Sparkles } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

export interface LlmPreviewItem {
    merchant_key: string;
    name: string;
    direction: 'entrata' | 'uscita';
    bank_category: string | null;
    count: number;
}

export interface LlmSkippedItem {
    merchant_key: string;
    label: string;
    reason: string;
    count: number;
}

export interface Pipeline {
    anonymization: Record<string, number>;
    kinds: { label: string; count: number }[];
    local: { memory: number; excluded: number; bank: number };
    llm: {
        provider: string;
        unavailable_reason: string | null;
        ran_at: string | null;
        stats: { sent: number; auto: number; to_review: number; unknown: number } | null;
        preview: { send: LlmPreviewItem[]; skipped: LlmSkippedItem[] } | null;
    };
}

type StepStatus = 'done' | 'current' | 'pending' | 'skipped' | 'running';

const ANONYMIZATION_LABELS: Record<string, string> = {
    cards: 'numeri di carta',
    ibans: 'IBAN',
    fiscal_codes: 'codici fiscali',
    accounts: 'numeri di conto',
    emails: 'email',
};

const PREVIEW_LIMIT = 12;

interface ImportPipelineProps {
    importId: number;
    filename: string;
    rowsTotal: number;
    rowsDuplicates: number;
    toReviewCount: number;
    readOnly: boolean;
    pipeline: Pipeline;
    llmError?: string;
}

/** The import as a sequence of steps: upload → anonymization → split → local rules → AI → review. */
export function ImportPipeline({
    importId,
    filename,
    rowsTotal,
    rowsDuplicates,
    toReviewCount,
    readOnly,
    pipeline,
    llmError,
}: ImportPipelineProps) {
    const [running, setRunning] = useState(false);
    const { llm } = pipeline;
    const toSend = llm.preview?.send ?? [];

    const llmStatus: StepStatus = running
        ? 'running'
        : llm.unavailable_reason !== null
          ? 'skipped'
          : toSend.length > 0 && !readOnly
            ? 'current'
            : llm.ran_at !== null
              ? 'done'
              : 'skipped';

    const masked = Object.entries(pipeline.anonymization).filter(([, count]) => count > 0);

    return (
        <ol className="space-y-0">
            <Step status="done" title="File letto">
                {filename} · {rowsTotal} movimenti nel file. Il file non è stato salvato.
            </Step>

            <Step status="done" title="Dati sensibili rimossi" icon={<ShieldCheck className="size-4" />}>
                {masked.length === 0
                    ? 'Nessun numero di carta, IBAN, codice fiscale o conto trovato nelle causali.'
                    : `Mascherati: ${masked.map(([key, count]) => `${count} ${ANONYMIZATION_LABELS[key] ?? key}`).join(', ')}.`}
            </Step>

            <Step status="done" title="Movimenti scorporati">
                <div className="flex flex-wrap gap-1">
                    {pipeline.kinds.map((kind) => (
                        <Badge key={kind.label} variant="outline" className="text-[10px]">
                            {kind.label}: {kind.count}
                        </Badge>
                    ))}
                    {rowsDuplicates > 0 && (
                        <Badge variant="secondary" className="text-[10px]">
                            Già importati e saltati: {rowsDuplicates}
                        </Badge>
                    )}
                </div>
            </Step>

            <Step status="done" title="Categorizzazione locale">
                {pipeline.local.memory} riconosciuti dalla memoria o da regole, {pipeline.local.excluded} esclusi
                (giroconti e simili), {pipeline.local.bank} con un suggerimento della banca da verificare.
            </Step>

            <Step
                status={llmStatus}
                title={`Categorizzazione AI · ${llm.provider}`}
                icon={<Sparkles className="size-4" />}
            >
                <LlmStep
                    importId={importId}
                    llm={llm}
                    readOnly={readOnly}
                    running={running}
                    onRunningChange={setRunning}
                    error={llmError}
                />
            </Step>

            <Step status={readOnly ? 'done' : toReviewCount > 0 ? 'current' : 'done'} title="Revisione e conferma" last>
                {readOnly
                    ? 'Import confermato: i consuntivi sono aggiornati.'
                    : toReviewCount > 0
                      ? `${toReviewCount} movimenti da confermare qui sotto, poi "Conferma import".`
                      : 'Tutti i movimenti hanno una categoria: controlla e premi "Conferma import".'}
            </Step>
        </ol>
    );
}

interface LlmStepProps {
    importId: number;
    llm: Pipeline['llm'];
    readOnly: boolean;
    running: boolean;
    onRunningChange: (running: boolean) => void;
    error?: string;
}

function LlmStep({ importId, llm, readOnly, running, onRunningChange, error }: LlmStepProps) {
    const [excluded, setExcluded] = useState<string[]>([]);
    const [showAll, setShowAll] = useState(false);
    const toSend = llm.preview?.send ?? [];
    const skipped = llm.preview?.skipped ?? [];
    const selectedCount = toSend.length - excluded.length;
    const visible = showAll ? toSend : toSend.slice(0, PREVIEW_LIMIT);

    function toggle(merchantKey: string, include: boolean) {
        setExcluded((prev) => (include ? prev.filter((k) => k !== merchantKey) : [...prev, merchantKey]));
    }

    function send() {
        router.post(
            `/bank-imports/${importId}/categorize`,
            { excluded },
            {
                preserveScroll: true,
                onStart: () => onRunningChange(true),
                onFinish: () => onRunningChange(false),
                onSuccess: () => setExcluded([]),
            },
        );
    }

    return (
        <div className="space-y-3">
            {llm.stats && (
                <p>
                    Inviati {llm.stats.sent} esercenti: {llm.stats.auto} categorizzati con sicurezza,{' '}
                    {llm.stats.to_review} da verificare, {llm.stats.unknown} non riconosciuti.
                </p>
            )}

            {llm.unavailable_reason !== null && <p>{llm.unavailable_reason}</p>}

            {llm.unavailable_reason === null && !readOnly && toSend.length === 0 && !llm.stats && (
                <p>Niente da chiedere all'AI: tutto è stato categorizzato localmente o va confermato a mano.</p>
            )}

            {llm.unavailable_reason === null && !readOnly && toSend.length > 0 && (
                <div className="space-y-2 rounded-md border bg-background p-3">
                    <p className="font-medium text-foreground">
                        Anteprima di cosa viene inviato: solo il nome dell'esercente e se è un'entrata o un'uscita.
                        Niente importi, date o dati personali.
                    </p>
                    <ul className="grid gap-1 sm:grid-cols-2">
                        {visible.map((item) => {
                            const checked = !excluded.includes(item.merchant_key);
                            const id = `send-${item.merchant_key}`;

                            return (
                                <li key={item.merchant_key} className="flex items-center gap-2">
                                    <Checkbox
                                        id={id}
                                        checked={checked}
                                        disabled={running}
                                        onCheckedChange={(value) => toggle(item.merchant_key, value === true)}
                                    />
                                    <label
                                        htmlFor={id}
                                        className={cn('truncate', !checked && 'line-through opacity-60')}
                                    >
                                        <span className="font-mono text-[11px] text-foreground">{item.name}</span>
                                        <span className="ml-1 text-[10px]">
                                            {item.direction}
                                            {item.bank_category && ` · banca: ${item.bank_category}`}
                                        </span>
                                    </label>
                                </li>
                            );
                        })}
                    </ul>
                    {toSend.length > PREVIEW_LIMIT && (
                        <button
                            type="button"
                            className="text-[11px] underline underline-offset-2"
                            onClick={() => setShowAll(!showAll)}
                        >
                            {showAll ? 'Mostra meno' : `Mostra tutti (${toSend.length})`}
                        </button>
                    )}

                    {skipped.length > 0 && (
                        <p className="text-[11px]">
                            Non inviati, da confermare a mano:{' '}
                            {skipped.map((item) => `${item.label} (${item.reason.toLowerCase()})`).join(', ')}.
                        </p>
                    )}

                    <Button size="sm" onClick={send} disabled={running || selectedCount === 0}>
                        {running ? (
                            <>
                                <Loader2 className="size-3.5 animate-spin" /> Analisi in corso…
                            </>
                        ) : (
                            <>
                                <Sparkles className="size-3.5" /> Invia {selectedCount} esercenti all'AI
                            </>
                        )}
                    </Button>
                </div>
            )}

            {error && <p className="text-destructive">{error}</p>}
        </div>
    );
}

interface StepProps {
    status: StepStatus;
    title: string;
    icon?: ReactNode;
    last?: boolean;
    children: ReactNode;
}

function Step({ status, title, icon, last, children }: StepProps) {
    const StatusIcon = {
        done: CheckCircle2,
        current: CircleDot,
        pending: Circle,
        skipped: MinusCircle,
        running: Loader2,
    }[status];

    return (
        <li className="flex gap-3">
            <div className="flex flex-col items-center">
                <StatusIcon
                    className={cn(
                        'mt-0.5 size-5 shrink-0',
                        status === 'done' && 'text-emerald-600 dark:text-emerald-400',
                        status === 'current' && 'text-primary',
                        status === 'running' && 'animate-spin text-primary',
                        (status === 'pending' || status === 'skipped') && 'text-muted-foreground',
                    )}
                    aria-label={status}
                />
                {!last && <div className="my-1 w-px flex-1 bg-border" />}
            </div>
            <div className={cn('min-w-0 flex-1 text-xs text-muted-foreground', !last && 'pb-4')}>
                <p className="flex items-center gap-1.5 text-sm font-semibold text-foreground">
                    {title}
                    {icon && <span className="text-muted-foreground">{icon}</span>}
                </p>
                <div className="mt-0.5">{children}</div>
            </div>
        </li>
    );
}
