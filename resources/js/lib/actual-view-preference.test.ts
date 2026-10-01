import { beforeEach, describe, expect, it } from 'vitest';
import { loadActualView, saveActualView, viewToRestore } from './actual-view-preference';

const current = { tab: 'month' as const, year: 2026, month: 10 };

beforeEach(() => {
    window.localStorage.clear();
});

describe('loadActualView', () => {
    it('round-trips a saved view', () => {
        saveActualView({ tab: 'year', year: 2025, month: 3 });

        expect(loadActualView()).toEqual({ tab: 'year', year: 2025, month: 3 });
    });

    it('returns null when nothing is saved', () => {
        expect(loadActualView()).toBeNull();
    });

    it('ignores corrupted or invalid data', () => {
        window.localStorage.setItem('varys.actual.view', '{not json');
        expect(loadActualView()).toBeNull();

        window.localStorage.setItem('varys.actual.view', JSON.stringify({ tab: 'week', year: 2025, month: 3 }));
        expect(loadActualView()).toBeNull();

        window.localStorage.setItem('varys.actual.view', JSON.stringify({ tab: 'month', year: 2025, month: 13 }));
        expect(loadActualView()).toBeNull();
    });
});

describe('viewToRestore', () => {
    it('restores the saved view when entering the page without params', () => {
        saveActualView({ tab: 'month', year: 2025, month: 4 });

        expect(viewToRestore('', current)).toEqual({ tab: 'month', year: 2025, month: 4 });
    });

    it('keeps the view chosen by the URL', () => {
        saveActualView({ tab: 'year', year: 2025, month: 4 });

        expect(viewToRestore('?tab=month&year=2026&month=10', current)).toBeNull();
    });

    it('does nothing when the saved view is already shown', () => {
        saveActualView(current);

        expect(viewToRestore('', current)).toBeNull();
    });
});
