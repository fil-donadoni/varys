export type CategoryKind = 'income' | 'expense';

export type VarianceTone = 'none' | 'good' | 'ok' | 'warn' | 'bad' | 'unbudgeted';

const OK_THRESHOLD = 0.1;
const WARN_THRESHOLD = 0.3;
// Keeps "exactly +10%" inside the band despite floating point (330 / 300 - 1 = 0.10000000000000009).
const EPSILON = 1e-9;

/**
 * Relative difference of actual vs budget; null when there is no budget to compare with.
 * Divides by the budget's size so a negative budget (net deficit) keeps the sign of the difference.
 */
export function varianceRatio(actual: number, budget: number): number | null {
    return budget === 0 ? null : (actual - budget) / Math.abs(budget);
}

/** How bad a category month looks: spending above budget (or earning below it) is worse. */
export function varianceTone(actual: number, budget: number, type: CategoryKind): VarianceTone {
    if (budget === 0) {
        if (actual === 0) {
            return 'none';
        }
        return type === 'expense' && actual > 0 ? 'unbudgeted' : 'good';
    }

    const ratio = (actual - budget) / Math.abs(budget);
    const overrun = type === 'expense' ? ratio : -ratio;

    if (overrun < -OK_THRESHOLD - EPSILON) {
        return 'good';
    }
    if (overrun <= OK_THRESHOLD + EPSILON) {
        return 'ok';
    }
    if (overrun <= WARN_THRESHOLD + EPSILON) {
        return 'warn';
    }
    return 'bad';
}

export function formatPercent(ratio: number | null): string {
    if (ratio === null) {
        return '—';
    }
    const rounded = Math.round(ratio * 100);
    return `${rounded > 0 ? '+' : ''}${rounded}%`;
}

export const TONE_CLASSES: Record<VarianceTone, string> = {
    none: 'text-muted-foreground',
    good: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    ok: 'bg-muted/60 text-foreground',
    warn: 'bg-amber-500/20 text-amber-800 dark:text-amber-300',
    bad: 'bg-red-500/20 text-red-700 dark:text-red-300',
    unbudgeted: 'bg-red-500/20 text-red-700 dark:text-red-300',
};

export const TONE_TEXT: Record<VarianceTone, string> = {
    none: 'text-muted-foreground',
    good: 'text-emerald-600 dark:text-emerald-400',
    ok: 'text-foreground',
    warn: 'text-amber-600 dark:text-amber-400',
    bad: 'text-destructive',
    unbudgeted: 'text-destructive',
};

export const TONE_LABEL: Record<VarianceTone, string> = {
    none: 'Nessun dato',
    good: 'Meglio del budget',
    ok: 'In linea (±10%)',
    warn: 'Sopra budget 10–30%',
    bad: 'Sopra budget oltre 30%',
    unbudgeted: 'Senza budget',
};
