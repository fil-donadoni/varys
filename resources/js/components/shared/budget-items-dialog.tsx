import { Plus, Trash2 } from 'lucide-react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatCurrency, parseAmount } from '@/lib/utils';

export interface BudgetItem {
    description: string;
    amount: number;
}

interface ItemRow {
    key: number;
    description: string;
    amount: string;
}

interface BudgetItemsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    subtitle: string;
    initialItems: { description: string; amount: string }[];
    /** Single value already in the cell, used to prefill the first row when there are no items yet. */
    initialAmount: string;
    processing: boolean;
    onSubmit: (items: BudgetItem[]) => void;
}

let nextRowKey = 0;

function makeRow(description = '', amount = ''): ItemRow {
    nextRowKey += 1;
    return { key: nextRowKey, description, amount };
}

function buildRows(initialItems: BudgetItemsDialogProps['initialItems'], initialAmount: string): ItemRow[] {
    if (initialItems.length > 0) {
        return initialItems.map((item) => makeRow(item.description, item.amount));
    }
    if (initialAmount.trim() !== '') {
        return [makeRow('', initialAmount), makeRow()];
    }
    return [makeRow(), makeRow()];
}

function isBlank(row: ItemRow): boolean {
    return row.description.trim() === '' && row.amount.trim() === '';
}

export function BudgetItemsDialog({
    open,
    onOpenChange,
    title,
    subtitle,
    initialItems,
    initialAmount,
    processing,
    onSubmit,
}: BudgetItemsDialogProps) {
    const [rows, setRows] = useState<ItemRow[]>(() => buildRows(initialItems, initialAmount));
    const [showErrors, setShowErrors] = useState(false);
    const lastDescriptionRef = useRef<HTMLInputElement>(null);
    const focusLastRef = useRef(false);

    useEffect(() => {
        if (focusLastRef.current) {
            focusLastRef.current = false;
            lastDescriptionRef.current?.focus();
        }
    }, [rows.length]);

    const filledRows = rows.filter((row) => !isBlank(row));
    const total = filledRows.reduce((sum, row) => sum + parseAmount(row.amount), 0);

    const rowError = (row: ItemRow): { description?: string; amount?: string } => {
        if (isBlank(row)) {
            return {};
        }
        const errors: { description?: string; amount?: string } = {};
        if (row.description.trim() === '') {
            errors.description = 'Causale obbligatoria';
        }
        if (row.amount.trim() === '' || parseAmount(row.amount) < 0) {
            errors.amount = 'Importo non valido';
        }
        return errors;
    };

    const hasErrors = filledRows.some((row) => Object.keys(rowError(row)).length > 0);

    const updateRow = (key: number, field: 'description' | 'amount', value: string) => {
        setRows((prev) => prev.map((row) => (row.key === key ? { ...row, [field]: value } : row)));
    };

    const addRow = () => {
        focusLastRef.current = true;
        setRows((prev) => [...prev, makeRow()]);
    };

    const removeRow = (key: number) => {
        setRows((prev) => prev.filter((row) => row.key !== key));
    };

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        if (hasErrors) {
            setShowErrors(true);
            return;
        }
        onSubmit(
            filledRows.map((row) => ({
                description: row.description.trim(),
                amount: parseAmount(row.amount),
            })),
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{subtitle}</DialogDescription>
                    </DialogHeader>

                    <div className="flex flex-col gap-2">
                        {rows.map((row, idx) => {
                            const errors = showErrors ? rowError(row) : {};
                            return (
                                <div key={row.key} className="flex items-start gap-2">
                                    <div className="flex-1">
                                        <Input
                                            ref={idx === rows.length - 1 ? lastDescriptionRef : undefined}
                                            value={row.description}
                                            onChange={(e) => updateRow(row.key, 'description', e.target.value)}
                                            placeholder="Causale"
                                            aria-label={`Causale riga ${idx + 1}`}
                                            aria-invalid={errors.description ? true : undefined}
                                            maxLength={255}
                                        />
                                        {errors.description && (
                                            <p className="mt-1 text-xs text-destructive">{errors.description}</p>
                                        )}
                                    </div>
                                    <div className="w-32">
                                        <Input
                                            type="text"
                                            inputMode="decimal"
                                            value={row.amount}
                                            onChange={(e) => updateRow(row.key, 'amount', e.target.value)}
                                            onFocus={(e) => e.target.select()}
                                            placeholder="0,00"
                                            className="text-right tabular-nums"
                                            aria-label={`Importo riga ${idx + 1}`}
                                            aria-invalid={errors.amount ? true : undefined}
                                        />
                                        {errors.amount && (
                                            <p className="mt-1 text-xs text-destructive">{errors.amount}</p>
                                        )}
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-9 shrink-0 text-muted-foreground"
                                        onClick={() => removeRow(row.key)}
                                        aria-label={`Rimuovi riga ${idx + 1}`}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            );
                        })}

                        {rows.length === 0 && (
                            <p className="py-2 text-sm text-muted-foreground">
                                Nessuna riga: salvando, la voce di budget verrà eliminata.
                            </p>
                        )}

                        <Button type="button" variant="outline" size="sm" className="self-start" onClick={addRow}>
                            <Plus className="size-4" />
                            Aggiungi riga
                        </Button>
                    </div>

                    <div className="flex items-center justify-between border-t pt-3 text-sm">
                        <span className="font-medium">Totale</span>
                        <span className="font-semibold tabular-nums" data-testid="budget-items-total">
                            {formatCurrency(total)}
                        </span>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Annulla
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Salva
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
