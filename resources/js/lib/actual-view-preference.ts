export type ActualTab = 'month' | 'year';

export interface ActualView {
    tab: ActualTab;
    year: number;
    month: number;
}

const STORAGE_KEY = 'varys.actual.view';

export function saveActualView(view: ActualView): void {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(view));
    } catch {
        // Storage unavailable (private mode, blocked): the preference is just not remembered.
    }
}

export function loadActualView(): ActualView | null {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        if (raw === null) {
            return null;
        }
        const parsed: unknown = JSON.parse(raw);
        if (typeof parsed !== 'object' || parsed === null) {
            return null;
        }
        const { tab, year, month } = parsed as Record<string, unknown>;
        if (
            (tab !== 'month' && tab !== 'year') ||
            !Number.isInteger(year) ||
            !Number.isInteger(month) ||
            (month as number) < 1 ||
            (month as number) > 12
        ) {
            return null;
        }
        return { tab, year: year as number, month: month as number };
    } catch {
        return null;
    }
}

/** The saved view to restore, or null when the URL already picks a view or nothing differs. */
export function viewToRestore(search: string, current: ActualView): ActualView | null {
    const params = new URLSearchParams(search);
    if (params.has('tab') || params.has('year') || params.has('month')) {
        return null;
    }
    const saved = loadActualView();
    if (saved === null) {
        return null;
    }
    const same = saved.tab === current.tab && saved.year === current.year && saved.month === current.month;
    return same ? null : saved;
}
