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
        expect(screen.getByTestId('budget-items-total')).toHaveTextContent('3.000,00');
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

        expect(screen.getByTestId('budget-items-total')).toHaveTextContent('3.000,50');

        fireEvent.click(screen.getByRole('button', { name: 'Salva' }));

        expect(onSubmit).toHaveBeenCalledWith([
            { description: 'Affitto', amount: 1200.5, is_invoiced: false },
            { description: 'Spese', amount: 1800, is_invoiced: false },
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

    it('hides the invoiced checkboxes when the category cannot be invoiced', () => {
        renderDialog({ initialItems: [{ description: 'Affitto', amount: '100.00' }] });

        expect(screen.queryByLabelText('Fatturata riga 1')).not.toBeInTheDocument();
        expect(screen.queryByTestId('budget-items-invoiced-total')).not.toBeInTheDocument();
    });

    it('shows each item invoiced flag and the invoiced subtotal', () => {
        renderDialog({
            invoiced: { defaultValue: true, initialAmountValue: false },
            initialItems: [
                { description: 'Cliente A', amount: '300.00', is_invoiced: true },
                { description: 'Cliente B', amount: '200.00', is_invoiced: false },
            ],
        });

        expect(screen.getByLabelText('Fatturata riga 1')).toBeChecked();
        expect(screen.getByLabelText('Fatturata riga 2')).not.toBeChecked();
        expect(screen.getByTestId('budget-items-invoiced-total')).toHaveTextContent('300,00');
    });

    it('defaults new rows to the category flag and the prefilled row to the entry flag', () => {
        const { onSubmit } = renderDialog({
            invoiced: { defaultValue: true, initialAmountValue: false },
            initialAmount: '500',
        });

        expect(screen.getByLabelText('Fatturata riga 1')).not.toBeChecked();
        expect(screen.getByLabelText('Fatturata riga 2')).toBeChecked();

        fireEvent.change(screen.getByLabelText('Causale riga 1'), { target: { value: 'Acconto' } });
        fireEvent.click(screen.getByLabelText('Fatturata riga 1'));
        fireEvent.change(screen.getByLabelText('Causale riga 2'), { target: { value: 'Saldo' } });
        fireEvent.change(screen.getByLabelText('Importo riga 2'), { target: { value: '250' } });

        expect(screen.getByTestId('budget-items-invoiced-total')).toHaveTextContent('750,00');

        fireEvent.click(screen.getByRole('button', { name: 'Salva' }));

        expect(onSubmit).toHaveBeenCalledWith([
            { description: 'Acconto', amount: 500, is_invoiced: true },
            { description: 'Saldo', amount: 250, is_invoiced: true },
        ]);
    });
});
