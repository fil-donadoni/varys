import { Head, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';

interface Props {
    settings: {
        saldo_iniziale: string;
        limite_fatturato_annuale: string;
    };
}

export default function SettingsIndex({ settings }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        saldo_iniziale: settings.saldo_iniziale,
        limite_fatturato_annuale: settings.limite_fatturato_annuale,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        put('/settings');
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
                                <Label htmlFor="saldo_iniziale">Saldo iniziale</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="saldo_iniziale"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.saldo_iniziale}
                                        onChange={(e) => setData('saldo_iniziale', e.target.value)}
                                        className="w-48"
                                        aria-invalid={!!errors.saldo_iniziale}
                                        aria-describedby={errors.saldo_iniziale ? 'saldo-error' : undefined}
                                    />
                                    <span className="text-sm text-muted-foreground">EUR</span>
                                </div>
                                {errors.saldo_iniziale && (
                                    <p id="saldo-error" className="text-sm text-destructive">
                                        {errors.saldo_iniziale}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="limite_fatturato">Limite fatturato annuale</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="limite_fatturato"
                                        type="number"
                                        min={0}
                                        step="0.01"
                                        value={data.limite_fatturato_annuale}
                                        onChange={(e) => setData('limite_fatturato_annuale', e.target.value)}
                                        className="w-48"
                                        aria-invalid={!!errors.limite_fatturato_annuale}
                                        aria-describedby={errors.limite_fatturato_annuale ? 'limite-error' : undefined}
                                    />
                                    <span className="text-sm text-muted-foreground">EUR</span>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Verrà mostrato un avviso in dashboard quando il budget delle entrate fatturate
                                    supera questo limite.
                                </p>
                                {errors.limite_fatturato_annuale && (
                                    <p id="limite-error" className="text-sm text-destructive">
                                        {errors.limite_fatturato_annuale}
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
            </div>
        </AppLayout>
    );
}
