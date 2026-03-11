import { Head, useForm } from '@inertiajs/react';
import { Download, Upload } from 'lucide-react';
import { useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';

interface Props {
    settings: {
        opening_balance: string;
        annual_invoice_limit: string;
    };
}

export default function SettingsIndex({ settings }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        opening_balance: settings.opening_balance,
        annual_invoice_limit: settings.annual_invoice_limit,
    });

    const importForm = useForm<{ file: File | null }>({ file: null });
    const fileInputRef = useRef<HTMLInputElement>(null);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        put('/settings');
    }

    function handleImport(e: React.FormEvent) {
        e.preventDefault();
        if (!importForm.data.file) return;

        importForm.post('/data/import', {
            forceFormData: true,
            onSuccess: () => {
                importForm.reset();
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    }

    return (
        <AppLayout>
            <Head title="Impostazioni" />

            <div className="space-y-6">
                <h1 className="text-2xl font-bold tracking-tight">Impostazioni</h1>

                <form onSubmit={handleSubmit} noValidate>
                    <Card className="max-w-lg">
                        <CardHeader>
                            <CardTitle>Parametri generali</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-5">
                            <div className="space-y-1.5">
                                <Label htmlFor="opening_balance">Saldo iniziale</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="opening_balance"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.opening_balance}
                                        onChange={(e) => setData('opening_balance', e.target.value)}
                                        className="w-48"
                                        aria-invalid={!!errors.opening_balance}
                                        aria-describedby={errors.opening_balance ? 'saldo-error' : undefined}
                                    />
                                    <span className="text-sm text-muted-foreground">EUR</span>
                                </div>
                                {errors.opening_balance && (
                                    <p id="saldo-error" className="text-sm text-destructive">
                                        {errors.opening_balance}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="annual_invoice_limit">Limite fatturato annuale</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="annual_invoice_limit"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.annual_invoice_limit}
                                        onChange={(e) => setData('annual_invoice_limit', e.target.value)}
                                        className="w-48"
                                        aria-invalid={!!errors.annual_invoice_limit}
                                        aria-describedby={errors.annual_invoice_limit ? 'limite-error' : undefined}
                                    />
                                    <span className="text-sm text-muted-foreground">EUR</span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Verrà mostrato un avviso in dashboard quando il budget delle entrate fatturate
                                    supera questo limite.
                                </p>
                                {errors.annual_invoice_limit && (
                                    <p id="limite-error" className="text-sm text-destructive">
                                        {errors.annual_invoice_limit}
                                    </p>
                                )}
                            </div>
                        </CardContent>

                        <CardFooter>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Salvataggio…' : 'Salva impostazioni'}
                            </Button>
                        </CardFooter>
                    </Card>
                </form>

                <Card className="max-w-lg">
                    <CardHeader>
                        <CardTitle>Backup e ripristino</CardTitle>
                        <CardDescription>
                            Esporta tutti i dati in un file ZIP contenente CSV modificabili, oppure ripristina da un
                            backup precedente.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="space-y-5">
                        <div className="space-y-1.5">
                            <Label>Esporta dati</Label>
                            <p className="text-xs text-muted-foreground">
                                Scarica un archivio ZIP con categorie, budget, consuntivo e impostazioni in formato CSV.
                            </p>
                            <Button variant="outline" asChild className="mt-1.5">
                                <a href="/data/export" download>
                                    <Download className="mr-2 size-4" />
                                    Scarica backup
                                </a>
                            </Button>
                        </div>

                        <div className="border-t pt-5">
                            <form onSubmit={handleImport} className="space-y-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="import_file">Importa dati</Label>
                                    <p className="text-xs text-muted-foreground">
                                        Carica un file ZIP esportato in precedenza. Tutti i dati esistenti verranno
                                        sostituiti.
                                    </p>
                                    <Input
                                        ref={fileInputRef}
                                        id="import_file"
                                        type="file"
                                        accept=".zip"
                                        onChange={(e) => importForm.setData('file', e.target.files?.[0] ?? null)}
                                        className="w-64"
                                        aria-invalid={!!importForm.errors.file}
                                    />
                                    {importForm.errors.file && (
                                        <p className="text-sm text-destructive">{importForm.errors.file}</p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={!importForm.data.file || importForm.processing}
                                >
                                    <Upload className="mr-2 size-4" />
                                    {importForm.processing ? 'Importazione…' : 'Ripristina backup'}
                                </Button>
                            </form>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
