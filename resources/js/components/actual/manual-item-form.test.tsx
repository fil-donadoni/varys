import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ManualItemForm } from './manual-item-form';

describe('ManualItemForm', () => {
    it('submits the parsed amount with a dot decimal', () => {
        const onSubmit = vi.fn();
        render(
            <ManualItemForm
                initial={{ date: '2026-08-01', description: '', amount: '' }}
                onSubmit={onSubmit}
                onCancel={() => {}}
            />,
        );

        fireEvent.change(screen.getByLabelText('Descrizione'), { target: { value: 'Cena amici' } });
        fireEvent.change(screen.getByLabelText('Importo'), { target: { value: '60,50' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salva' }));

        expect(onSubmit).toHaveBeenCalledWith({ date: '2026-08-01', description: 'Cena amici', amount: '60.5' });
    });

    it('does not submit without description or with zero amount', () => {
        const onSubmit = vi.fn();
        render(
            <ManualItemForm
                initial={{ date: '2026-08-01', description: '', amount: '0' }}
                onSubmit={onSubmit}
                onCancel={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Salva' }));

        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Salva' })).toBeDisabled();
    });
});
