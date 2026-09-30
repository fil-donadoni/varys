import { router } from '@inertiajs/react';
import { Landmark, Pencil, PenLine, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { CategoryCombobox } from '@/components/shared/category-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/utils';
import { ManualItemForm, type ManualItemValues } from './manual-item-form';
import { type ActualCategory, type ActualLine, shortDate } from './types';

interface LineRowProps {
    line: ActualLine;
    category: ActualCategory;
    categories: ActualCategory[];
}

const onError = () => toast.error('Operazione non riuscita');

/** One movement or manual item inside an expanded category: bank lines move/exclude, manual ones edit/delete. */
export function LineRow({ line, category, categories }: LineRowProps) {
    const [editing, setEditing] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [processing, setProcessing] = useState(false);
    const options = { preserveScroll: true, onError, onFinish: () => setProcessing(false) };

    function reassign(categoryId: number | null, exclude: boolean) {
        setProcessing(true);
        router.patch(`/bank-transactions/${line.id}/reassign`, { category_id: categoryId, exclude }, options);
    }

    function update(values: ManualItemValues) {
        setProcessing(true);
        router.put(
            `/actual-items/${line.id}`,
            { ...values, category_id: category.id },
            { ...options, onSuccess: () => setEditing(false) },
        );
    }

    function destroy() {
        setProcessing(true);
        router.delete(`/actual-items/${line.id}`, options);
    }

    if (editing) {
        return (
            <li className="pl-8">
                <ManualItemForm
                    initial={{
                        date: line.date,
                        description: line.description,
                        amount: String(line.amount).replace('.', ','),
                    }}
                    processing={processing}
                    onSubmit={update}
                    onCancel={() => setEditing(false)}
                />
            </li>
        );
    }

    return (
        <li className="flex items-center gap-3 py-1 pl-8 text-xs">
            <span className="w-12 text-muted-foreground tabular-nums">{shortDate(line.date)}</span>
            <span className="min-w-0 flex-1 truncate" title={line.description}>
                {line.description}
            </span>
            <Badge variant="outline" className="gap-1 text-[10px] font-normal">
                {line.kind === 'bank' ? <Landmark className="size-3" /> : <PenLine className="size-3" />}
                {line.kind === 'bank' ? (line.kind_label ?? 'Banca') : 'Manuale'}
            </Badge>
            <span className="w-24 text-right font-medium tabular-nums">{formatCurrency(line.amount)}</span>
            <div className="flex w-60 justify-end gap-1">
                {line.kind === 'bank' ? (
                    <CategoryCombobox
                        categories={categories}
                        amount={line.bank_amount ?? 0}
                        value={category.id}
                        excluded={false}
                        disabled={processing}
                        ariaLabel={`Categoria di ${line.description}`}
                        onChange={reassign}
                    />
                ) : confirmDelete ? (
                    <>
                        <span className="self-center text-muted-foreground">Eliminare?</span>
                        <Button
                            size="sm"
                            variant="destructive"
                            className="h-7 text-xs"
                            disabled={processing}
                            onClick={destroy}
                        >
                            Elimina
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 text-xs"
                            onClick={() => setConfirmDelete(false)}
                        >
                            No
                        </Button>
                    </>
                ) : (
                    <>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            aria-label={`Modifica ${line.description}`}
                            onClick={() => setEditing(true)}
                        >
                            <Pencil className="size-3.5" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            aria-label={`Elimina ${line.description}`}
                            onClick={() => setConfirmDelete(true)}
                        >
                            <Trash2 className="size-3.5" />
                        </Button>
                    </>
                )}
            </div>
        </li>
    );
}
