import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { parseAmount } from '@/lib/utils';

export interface ManualItemValues {
    date: string;
    description: string;
    amount: string;
}

interface ManualItemFormProps {
    initial: ManualItemValues;
    processing?: boolean;
    onSubmit: (values: ManualItemValues) => void;
    onCancel: () => void;
}

/** Inline row form for adding or editing a manual actual item. Amount accepts "60,50" and "1.200". */
export function ManualItemForm({ initial, processing = false, onSubmit, onCancel }: ManualItemFormProps) {
    const [values, setValues] = useState(initial);
    const amount = parseAmount(values.amount);
    const valid = values.date !== '' && values.description.trim() !== '' && amount !== 0;

    function submit(event: FormEvent) {
        event.preventDefault();
        if (!valid) {
            return;
        }
        onSubmit({ date: values.date, description: values.description.trim(), amount: String(amount) });
    }

    return (
        <form onSubmit={submit} className="flex flex-wrap items-center gap-2 py-1">
            <Input
                type="date"
                aria-label="Data"
                value={values.date}
                onChange={(e) => setValues({ ...values, date: e.target.value })}
                className="h-7 w-36 text-xs"
            />
            <Input
                aria-label="Descrizione"
                placeholder="Descrizione…"
                value={values.description}
                onChange={(e) => setValues({ ...values, description: e.target.value })}
                className="h-7 min-w-48 flex-1 text-xs"
                autoFocus
            />
            <Input
                aria-label="Importo"
                inputMode="decimal"
                placeholder="0,00"
                value={values.amount}
                onChange={(e) => setValues({ ...values, amount: e.target.value })}
                className="h-7 w-28 text-right text-xs tabular-nums"
            />
            <Button type="submit" size="sm" className="h-7 text-xs" disabled={!valid || processing}>
                Salva
            </Button>
            <Button type="button" size="sm" variant="ghost" className="h-7 text-xs" onClick={onCancel}>
                Annulla
            </Button>
        </form>
    );
}
