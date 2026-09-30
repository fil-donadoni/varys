import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { LineRow } from './line-row';
import type { ActualCategory, ActualLine } from './types';

vi.mock('@inertiajs/react', () => ({ router: { patch: vi.fn(), put: vi.fn(), delete: vi.fn() } }));

const categories: ActualCategory[] = [
    { id: 1, name: 'Pranzi/cene', type: 'expense', color: null, sort_order: 1 },
    { id: 2, name: 'Spesa casa', type: 'expense', color: null, sort_order: 2 },
];

const line: ActualLine = {
    key: 'bank-7',
    kind: 'bank',
    id: 7,
    date: '2026-08-08',
    description: 'CAFFE MAINO',
    amount: 5.5,
    bank_amount: -5.5,
    kind_label: 'Carta',
};

function renderRow() {
    render(
        <ul>
            <LineRow line={line} category={categories[0]} categories={categories} />
        </ul>,
    );
    fireEvent.click(screen.getByRole('combobox', { name: 'Categoria di CAFFE MAINO' }));
    fireEvent.click(screen.getByText(/Escludi \(giroconto/));
}

describe('LineRow bank movement', () => {
    beforeEach(() => vi.mocked(router.patch).mockClear());

    it('asks before excluding a movement, which cannot be restored from here', () => {
        renderRow();

        expect(router.patch).not.toHaveBeenCalled();
        expect(screen.getByText(/Escludere/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Escludi' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/bank-transactions/7/reassign',
            { category_id: null, exclude: true },
            expect.anything(),
        );
    });

    it('keeps the movement when the exclusion is cancelled', () => {
        renderRow();

        fireEvent.click(screen.getByRole('button', { name: 'No' }));

        expect(router.patch).not.toHaveBeenCalled();
        expect(screen.getByRole('combobox', { name: 'Categoria di CAFFE MAINO' })).toBeInTheDocument();
    });
});
