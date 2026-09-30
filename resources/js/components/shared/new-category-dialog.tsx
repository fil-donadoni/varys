import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const PALETTE = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#14b8a6', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899'];

export interface NewCategoryData {
    name: string;
    type: 'income' | 'expense';
    color: string;
    apply_to_merchant: boolean;
}

interface NewCategoryDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Text the user was searching in the combobox. */
    defaultName: string;
    /** Income is allowed only for money coming in. */
    allowIncome: boolean;
    /** Label of the merchant the category will be assigned to. */
    merchantLabel: string;
    defaultApplyToMerchant: boolean;
    processing: boolean;
    errors: Partial<Record<keyof NewCategoryData, string>>;
    onSubmit: (data: NewCategoryData) => void;
}

/** Creates a category from the import review and assigns it right away. Mount it with a `key` to reset the form. */
export function NewCategoryDialog({
    open,
    onOpenChange,
    defaultName,
    allowIncome,
    merchantLabel,
    defaultApplyToMerchant,
    processing,
    errors,
    onSubmit,
}: NewCategoryDialogProps) {
    const [data, setData] = useState<NewCategoryData>(() => ({
        name: defaultName,
        type: allowIncome ? 'income' : 'expense',
        color: PALETTE[Math.floor(Math.random() * PALETTE.length)],
        apply_to_merchant: defaultApplyToMerchant,
    }));

    function submit(e: React.FormEvent) {
        e.preventDefault();
        onSubmit({ ...data, name: data.name.trim() });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Nuova categoria</DialogTitle>
                        <DialogDescription>
                            Viene creata e assegnata subito a <span className="font-medium">{merchantLabel}</span>.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-2">
                        <Label htmlFor="new-category-name">Nome</Label>
                        <Input
                            id="new-category-name"
                            autoFocus
                            value={data.name}
                            onChange={(e) => setData({ ...data, name: e.target.value })}
                            aria-invalid={!!errors.name}
                            placeholder="Es. Acquisti online"
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <div className="flex gap-4">
                        <div className="flex-1 space-y-2">
                            <Label htmlFor="new-category-type">Tipo</Label>
                            {allowIncome ? (
                                <Select
                                    value={data.type}
                                    onValueChange={(type) =>
                                        setData({ ...data, type: type as NewCategoryData['type'] })
                                    }
                                >
                                    <SelectTrigger id="new-category-type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="income">Entrata</SelectItem>
                                        <SelectItem value="expense">Uscita (es. rimborso)</SelectItem>
                                    </SelectContent>
                                </Select>
                            ) : (
                                <p
                                    id="new-category-type"
                                    className="flex h-9 items-center text-sm text-muted-foreground"
                                >
                                    Uscita
                                </p>
                            )}
                            {errors.type && <p className="text-sm text-destructive">{errors.type}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="new-category-color">Colore</Label>
                            <Input
                                id="new-category-color"
                                type="color"
                                value={data.color}
                                onChange={(e) => setData({ ...data, color: e.target.value })}
                                className="h-9 w-16 p-1"
                            />
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="new-category-apply"
                            checked={data.apply_to_merchant}
                            onCheckedChange={(checked) => setData({ ...data, apply_to_merchant: checked === true })}
                        />
                        <Label htmlFor="new-category-apply" className="text-sm font-normal">
                            Assegna a tutti i movimenti di questo esercente
                        </Label>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Annulla
                        </Button>
                        <Button type="submit" disabled={processing || data.name.trim() === ''}>
                            {processing ? 'Salvataggio…' : 'Crea e assegna'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
