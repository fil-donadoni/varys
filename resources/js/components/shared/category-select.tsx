import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { categoriesFor, EXCLUDE_VALUE, type ImportCategory } from '@/lib/bank-import';

interface CategorySelectProps {
    categories: ImportCategory[];
    /** Signed bank amount: decides which categories fit. */
    amount: number;
    value: number | null;
    excluded: boolean;
    disabled?: boolean;
    ariaLabel: string;
    onChange: (categoryId: number | null, exclude: boolean) => void;
}

export function CategorySelect({
    categories,
    amount,
    value,
    excluded,
    disabled,
    ariaLabel,
    onChange,
}: CategorySelectProps) {
    const options = categoriesFor(amount, categories);
    const income = options.filter((c) => c.type === 'income');
    const expense = options.filter((c) => c.type === 'expense');
    const current = excluded ? EXCLUDE_VALUE : value !== null ? String(value) : undefined;

    return (
        <Select
            value={current}
            disabled={disabled}
            onValueChange={(next) => (next === EXCLUDE_VALUE ? onChange(null, true) : onChange(Number(next), false))}
        >
            <SelectTrigger className="h-7 w-52 text-xs" aria-label={ariaLabel}>
                <SelectValue placeholder="Scegli categoria…" />
            </SelectTrigger>
            <SelectContent>
                {income.length > 0 && (
                    <SelectGroup>
                        <SelectLabel>Entrate</SelectLabel>
                        {income.map((c) => (
                            <SelectItem key={c.id} value={String(c.id)}>
                                {c.name}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                )}
                <SelectGroup>
                    <SelectLabel>Uscite</SelectLabel>
                    {expense.map((c) => (
                        <SelectItem key={c.id} value={String(c.id)}>
                            {c.name}
                        </SelectItem>
                    ))}
                </SelectGroup>
                <SelectSeparator />
                <SelectItem value={EXCLUDE_VALUE}>Escludi (giroconto, finanziamento…)</SelectItem>
            </SelectContent>
        </Select>
    );
}
