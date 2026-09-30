import { Head, Link, router, useForm } from '@inertiajs/react';
import { Trash2, Upload } from 'lucide-react';
import { useRef } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';

interface BankImportRow {
    id: number;
    bank: string;
    original_filename: string;
    period_start: string | null;
    period_end: string | null;
    rows_imported: number;
    rows_duplicates: number;
    status: 'review' | 'completed';
    status_label: string;
    created_at: string | null;
}

interface Props {
    imports: BankImportRow[];
    banks: { value: string; label: string }[];
}

const AUTO_DETECT = 'auto';

function formatDate(date: string | null): string {
    return date ? new Date(date).toLocaleDateString('it-IT') : '—';
}

export default function BankImportsIndex({ imports, banks }: Props) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const form = useForm<{ file: File | null; bank: string }>({ file: null, bank: AUTO_DETECT });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.transform((data) => ({ ...data, bank: data.bank === AUTO_DETECT ? null : data.bank }));
        form.post('/bank-imports', { forceFormData: true });
    }

    function destroy(row: BankImportRow) {
        const message =
            row.status === 'completed'
                ? 'Eliminare questo import? I suoi importi verranno tolti dai consuntivi.'
                : 'Eliminare questo import?';
        if (!window.confirm(message)) return;

        router.delete(`/bank-imports/${row.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success('Import eliminato'),
        });
    }

    return (
        <AppLayout>
            <Head title="Import movimenti" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Import movimenti</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Carica l'export Excel dei movimenti di Intesa Sanpaolo o ING: i consuntivi si compilano dopo la
                        tua conferma. Il file non viene salvato.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Nuovo import</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                            <div className="space-y-2">
                                <Label htmlFor="file">File Excel</Label>
                                <Input
                                    ref={fileInputRef}
                                    id="file"
                                    type="file"
                                    accept=".xlsx,.xls"
                                    onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                                    aria-invalid={!!form.errors.file}
                                    className="w-80"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="bank">Banca</Label>
                                <Select value={form.data.bank} onValueChange={(value) => form.setData('bank', value)}>
                                    <SelectTrigger id="bank" className="w-56">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={AUTO_DETECT}>Riconosci automaticamente</SelectItem>
                                        {banks.map((bank) => (
                                            <SelectItem key={bank.value} value={bank.value}>
                                                {bank.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button type="submit" disabled={!form.data.file || form.processing}>
                                <Upload className="size-4" />
                                {form.processing ? 'Lettura in corso…' : 'Importa'}
                            </Button>
                        </form>
                        {form.errors.file && <p className="mt-2 text-sm text-destructive">{form.errors.file}</p>}
                    </CardContent>
                </Card>

                <div className="rounded-lg border bg-card shadow-xs">
                    <Table className="text-xs">
                        <TableHeader>
                            <TableRow className="bg-muted hover:bg-muted/40">
                                <TableHead className="pl-3 font-semibold">Caricato il</TableHead>
                                <TableHead className="font-semibold">Banca</TableHead>
                                <TableHead className="font-semibold">File</TableHead>
                                <TableHead className="font-semibold">Periodo</TableHead>
                                <TableHead className="text-right font-semibold">Movimenti</TableHead>
                                <TableHead className="text-right font-semibold">Duplicati</TableHead>
                                <TableHead className="font-semibold">Stato</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {imports.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={8} className="py-6 text-center text-muted-foreground">
                                        Nessun import
                                    </TableCell>
                                </TableRow>
                            )}
                            {imports.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell className="pl-3">
                                        {row.created_at ? new Date(row.created_at).toLocaleString('it-IT') : '—'}
                                    </TableCell>
                                    <TableCell>{row.bank}</TableCell>
                                    <TableCell className="max-w-56 truncate">{row.original_filename}</TableCell>
                                    <TableCell>
                                        {formatDate(row.period_start)} – {formatDate(row.period_end)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{row.rows_imported}</TableCell>
                                    <TableCell className="text-right tabular-nums">{row.rows_duplicates}</TableCell>
                                    <TableCell>
                                        <Badge variant={row.status === 'completed' ? 'secondary' : 'default'}>
                                            {row.status_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <div className="flex justify-end gap-1">
                                            <Button asChild variant="outline" size="sm" className="h-7 text-xs">
                                                <Link href={`/bank-imports/${row.id}`}>
                                                    {row.status === 'completed' ? 'Apri' : 'Rivedi'}
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7"
                                                onClick={() => destroy(row)}
                                                aria-label="Elimina import"
                                            >
                                                <Trash2 className="size-3.5" />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
