import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { BudgetItemsDialog } from './budget-items-dialog';

function renderDialog(props: Partial<React.ComponentProps<typeof BudgetItemsDialog>> = {}) {
    const onSubmit = vi.fn();
    render(
        <BudgetItemsDialog
            open
            onOpenChange={() => {}}
            title="Dettaglio budget"
            subtitle="Aprile 2026"
            initialItems={[]}
            initialAmount=""
            processing={false}
            onSubmit={onSubmit}
            {...props}
        />,
    );
    return { onSubmit };
}

describe('BudgetItemsDialog', () => {
    it('shows existing items and their total', () => {
        renderDialog({
            initialItems: [
                { description: 'Cliente A', amount: '1200.00' },
                { description: 'Cliente B', amount: '1800.00' },
            ],
        });

        expect(screen.getByLabelText('Causale riga 1')).toHaveValue('Cliente A');
        expect(screen.getByLabelText('Importo riga 2')).toHaveValue('1800.00');
        expect(screen.getByTestId('budget-items-total')).toHaveTextContent('3000,00');
    });

    it('prefills the first row with the existing single amount', () => {
        renderDialog({ initialAmount: '3000' });

        expect(screen.getByLabelText('Importo riga 1')).toHaveValue('3000');
        expect(screen.getByLabelText('Causale riga 2')).toHaveValue('');
    });

    it('submits filled rows skipping blank ones', () => {
        const { onSubmit } = renderDialog();

        fireEvent.change(screen.getByLabelText('Causale riga 1'), { target: { value: ' Affitto ' } });
        fireEvent.change(screen.getByLabelText('Importo riga 1'), { target: { value: '1200,50' } });
        fireEvent.click(screen.getByRole('button', { name: 'Aggiungi riga' }));
        fireEvent.change(screen.getByLabelText('Causale riga 3'), { target: { value: 'Spese' } });
        fireEvent.change(screen.getByLabelText('Importo riga 3'), { target: { value: '1800' } });

        expect(screen.getByTestId('budget-items-total')).toHaveTextContent('3000,50');

        fireEvent.click(screen.getByRole('button', { name: 'Salva' }));

        expect(onSubmit).toHaveBeenCalledWith([
            { description: 'Affitto', amount: 1200.5 },
            { description: 'Spese', amount: 1800 },
        ]);
    });

    it('blocks submit when a row has an amount but no description', () => {
        const { onSubmit } = renderDialog({ initialAmount: '500' });

        fireEvent.click(screen.getByRole('button', { name: 'Salva' }));

        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByText('Causale obbligatoria')).toBeInTheDocument();
    });

    it('submits an empty list when all rows are removed', () => {
        const { onSubmit } = renderDialog({ initialItems: [{ description: 'Unica', amount: '100.00' }] });

        fireEvent.click(screen.getByRole('button', { name: 'Rimuovi riga 1' }));
        expect(screen.getByText(/la voce di budget verrà eliminata/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Salva' }));

        expect(onSubmit).toHaveBeenCalledWith([]);
    });
});
