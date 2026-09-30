import { CheckCircle2, Circle, CircleDot, Loader2, Lock, MinusCircle, ShieldCheck, Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

export interface AiControls {
    selectedCount: number;
    running: boolean;
    onSend: () => void;
}

interface ImportPipelineProps {
    filename: string;
    rowsTotal: number;
    rowsDuplicates: number;
    toReviewCount: number;
    readOnly: boolean;
    pipeline: Pipeline;
    ai: AiControls;
    llmError?: string;
}

/** The import as a sequence of steps: upload → anonymization → split → local rules → AI → review. */
export function ImportPipeline({
    filename,
    rowsTotal,
    rowsDuplicates,
    toReviewCount,
    readOnly,
    pipeline,
    ai,
    llmError,
}: ImportPipelineProps) {
    const { llm } = pipeline;
    const toSend = llm.preview?.send ?? [];

    const llmStatus: StepStatus = ai.running
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
                <LlmStep llm={llm} readOnly={readOnly} ai={ai} error={llmError} />
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
    llm: Pipeline['llm'];
    readOnly: boolean;
    ai: AiControls;
    error?: string;
}

function LlmStep({ llm, readOnly, ai, error }: LlmStepProps) {
    const available = llm.unavailable_reason === null && !readOnly;
    const hasCandidates = (llm.preview?.send.length ?? 0) > 0;
    const privateCount = llm.preview?.skipped.length ?? 0;

    return (
        <div className="space-y-2">
            {llm.stats && (
                <p>
                    L'AI ha proposto una categoria per {llm.stats.sent - llm.stats.unknown} esercenti su{' '}
                    {llm.stats.sent}: {llm.stats.auto} sicure (già tra i "Pronti"), {llm.stats.to_review} da verificare
                    nella tabella.
                </p>
            )}

            {llm.unavailable_reason !== null && <p>{llm.unavailable_reason}</p>}

            {available && !hasCandidates && !llm.stats && (
                <p>Niente da chiedere all'AI: tutto è stato riconosciuto localmente o resta privato.</p>
            )}

            {available && hasCandidates && (
                <div className="flex flex-wrap items-center gap-3">
                    <Button size="sm" onClick={ai.onSend} disabled={ai.running || ai.selectedCount === 0}>
                        {ai.running ? (
                            <>
                                <Loader2 className="size-3.5 animate-spin" /> Analisi in corso…
                            </>
                        ) : (
                            <>
                                <Sparkles className="size-3.5" /> Chiedi all'AI di proporre le categorie (
                                {ai.selectedCount})
                            </>
                        )}
                    </Button>
                    <p className="max-w-xl">
                        Riceve solo il nome degli esercenti segnati con{' '}
                        <Sparkles className="inline size-3 text-primary" /> nella tabella: niente importi, date o dati
                        personali. Clicca l'icona per escluderne uno.
                        {privateCount > 0 && (
                            <>
                                {' '}
                                {privateCount} restano privati <Lock className="inline size-3" /> e li scegli tu.
                            </>
                        )}
                    </p>
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
