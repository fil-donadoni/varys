import { Link, useForm } from '@inertiajs/react';
import { FileSpreadsheet, ShieldCheck, Upload } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const AUTO_DETECT = 'auto';

const BANKS = [
    { value: 'intesa', label: 'Intesa Sanpaolo' },
    { value: 'ing', label: 'ING' },
];

/** Upload of a bank export: after the upload the user lands on the review page. */
export function BankImportDialog() {
    const [open, setOpen] = useState(false);
    const form = useForm<{ file: File | null; bank: string }>({ file: null, bank: AUTO_DETECT });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.transform((data) => ({ ...data, bank: data.bank === AUTO_DETECT ? null : data.bank }));
        form.post('/bank-imports', { forceFormData: true });
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <FileSpreadsheet className="size-4" />
                    Importa movimenti
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Importa movimenti bancari</DialogTitle>
                        <DialogDescription>
                            Carica l'export Excel originale di Intesa Sanpaolo o ING: i consuntivi si aggiornano solo
                            dopo la tua conferma.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-2">
                        <Label htmlFor="bank-file">File Excel (.xlsx)</Label>
                        <Input
                            id="bank-file"
                            type="file"
                            accept=".xlsx,.xls"
                            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                            aria-invalid={!!form.errors.file}
                        />
                        {form.errors.file && <p className="text-sm text-destructive">{form.errors.file}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="bank-select">Banca</Label>
                        <Select value={form.data.bank} onValueChange={(value) => form.setData('bank', value)}>
                            <SelectTrigger id="bank-select">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={AUTO_DETECT}>Riconosci automaticamente</SelectItem>
                                {BANKS.map((bank) => (
                                    <SelectItem key={bank.value} value={bank.value}>
                                        {bank.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="flex gap-2 rounded-md bg-muted/50 p-3 text-xs text-muted-foreground">
                        <ShieldCheck className="size-4 shrink-0 text-emerald-600" />
                        <p>
                            Il file non viene salvato. Numeri di carta, IBAN, codici fiscali e numeri di conto vengono
                            mascherati subito; all'AI arrivano solo i nomi degli esercenti, dopo la tua approvazione.
                        </p>
                    </div>

                    <DialogFooter className="items-center sm:justify-between">
                        <Link href="/bank-imports" className="text-xs text-muted-foreground hover:text-foreground">
                            Storico import
                        </Link>
                        <Button type="submit" disabled={!form.data.file || form.processing}>
                            <Upload className="size-4" />
                            {form.processing ? 'Lettura e anonimizzazione…' : 'Carica e analizza'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
