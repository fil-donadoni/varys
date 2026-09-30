import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { ImportCategory } from '@/lib/bank-import';
import { CategoryCombobox } from './category-combobox';

const categories: ImportCategory[] = [
    { id: 1, name: 'Stipendio', type: 'income', color: '#22c55e' },
    { id: 2, name: 'Spesa casa', type: 'expense', color: '#ec4899' },
    { id: 3, name: 'Ristoranti', type: 'expense', color: null },
];

function renderCombobox(props: Partial<React.ComponentProps<typeof CategoryCombobox>> = {}) {
    const onChange = vi.fn();
    const onCreate = vi.fn();
    render(
        <CategoryCombobox
            categories={categories}
            amount={-10}
            value={null}
            excluded={false}
            ariaLabel="Categoria IPER"
            onChange={onChange}
            onCreate={onCreate}
            {...props}
        />,
    );
    fireEvent.click(screen.getByRole('combobox', { name: 'Categoria IPER' }));

    return { onChange, onCreate };
}

describe('CategoryCombobox', () => {
    it('shows the selected category', () => {
        render(
            <CategoryCombobox
                categories={categories}
                amount={-10}
                value={2}
                excluded={false}
                ariaLabel="Categoria IPER"
                onChange={() => {}}
                onCreate={() => {}}
            />,
        );

        expect(screen.getByRole('combobox', { name: 'Categoria IPER' })).toHaveTextContent('Spesa casa');
    });

    it('offers only expense categories for money going out and filters by search', () => {
        renderCombobox();

        expect(screen.queryByText('Stipendio')).not.toBeInTheDocument();

        fireEvent.change(screen.getByPlaceholderText('Cerca categoria…'), { target: { value: 'risto' } });

        expect(screen.getByText('Ristoranti')).toBeInTheDocument();
        expect(screen.queryByText('Spesa casa')).not.toBeInTheDocument();

        // Substring, not fuzzy: "spes" must not match "Ristoranti".
        fireEvent.change(screen.getByPlaceholderText('Cerca categoria…'), { target: { value: 'spes' } });

        expect(screen.getByText('Spesa casa')).toBeInTheDocument();
        expect(screen.queryByText('Ristoranti')).not.toBeInTheDocument();
    });

    it('selects a category', () => {
        const { onChange } = renderCombobox();

        fireEvent.click(screen.getByText('Spesa casa'));

        expect(onChange).toHaveBeenCalledWith(2, false);
    });

    it('creates a new category from the search text', () => {
        const { onCreate } = renderCombobox();

        fireEvent.change(screen.getByPlaceholderText('Cerca categoria…'), { target: { value: 'Acquisti online' } });
        fireEvent.click(screen.getByText('Crea "Acquisti online"…'));

        expect(onCreate).toHaveBeenCalledWith('Acquisti online');
    });

    it('excludes the movement', () => {
        const { onChange } = renderCombobox();

        fireEvent.click(screen.getByText('Escludi (giroconto, finanziamento…)'));

        expect(onChange).toHaveBeenCalledWith(null, true);
    });
});
