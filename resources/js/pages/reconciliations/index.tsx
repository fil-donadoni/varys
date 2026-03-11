import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn, formatCurrency } from '@/lib/utils';

const ITALIAN_MONTHS = [
    'Gennaio',
    'Febbraio',
    'Marzo',
    'Aprile',
    'Maggio',
    'Giugno',
    'Luglio',
    'Agosto',
    'Settembre',
    'Ottobre',
    'Novembre',
    'Dicembre',
];

interface Reconciliation {
    id: number;
    year: number;
    month: number;
    declared_balance: string;
    calculated_balance: string;
    adjustment: string;
    notes: string | null;
}

interface Props {
    year: number;
    reconciliations: Reconciliation[];
}

export default function ReconciliationsIndex({ year, reconciliations }: Props) {
    const [calculatedBalance, setCalculatedBalance] = useState<number | null>(null);
    const [isCalculating, setIsCalculating] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        year: String(year),
        month: '',
        declared_balance: '',
        notes: '',
    });

    function navigateYear(delta: number) {
        router.get('/reconciliations', { year: year + delta }, { preserveState: true });
    }

    async function handleMonthChange(month: string) {
        setData('month', month);
        setCalculatedBalance(null);

        if (!month) return;

        setIsCalculating(true);
        try {
            const response = await fetch('/reconciliations/calculate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ year, month: parseInt(month) }),
            });
            const result = await response.json();
            setCalculatedBalance(result.calculated_balance);
        } finally {
            setIsCalculating(false);
        }
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/reconciliations', {
            onSuccess: () => {
                reset();
                setCalculatedBalance(null);
            },
        });
    }

    function handleDelete(id: number) {
        router.delete(`/reconciliations/${id}`, {
            preserveScroll: true,
        });
    }

    const adjustment =
        calculatedBalance !== null && data.declared_balance
            ? parseFloat(data.declared_balance.replace(',', '.')) - calculatedBalance
            : null;

    return (
        <AppLayout>
            <Head title="Riconciliazione" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold tracking-tight">Riconciliazione</h1>
                    <div className="flex items-center gap-2">
                        <button
                            onClick={() => navigateYear(-1)}
                            aria-label="Anno precedente"
                            className="flex h-9 w-9 items-center justify-center rounded-md border border-input bg-background text-sm font-medium shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            &#8249;
                        </button>
                        <span className="min-w-16 text-center text-lg font-semibold tabular-nums">{year}</span>
                        <button
                            onClick={() => navigateYear(1)}
                            aria-label="Anno successivo"
                            className="flex h-9 w-9 items-center justify-center rounded-md border border-input bg-background text-sm font-medium shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            &#8250;
                        </button>
                    </div>
                </div>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Nuova riconciliazione</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div className="space-y-1.5">
                                <Label htmlFor="month">Mese</Label>
                                <Select value={data.month} onValueChange={handleMonthChange}>
                                    <SelectTrigger className="w-48">
                                        <SelectValue placeholder="Seleziona mese" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {ITALIAN_MONTHS.map((name, i) => (
                                            <SelectItem key={i + 1} value={String(i + 1)}>
                                                {name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.month && <p className="text-sm text-destructive">{errors.month}</p>}
                            </div>

                            {calculatedBalance !== null && (
                                <div className="rounded-lg border bg-muted/50 p-3 text-sm">
                                    <p>
                                        <span className="text-muted-foreground">Saldo calcolato a fine </span>
                                        <span className="font-medium">
                                            {ITALIAN_MONTHS[parseInt(data.month) - 1]} {year}
                                        </span>
                                        <span className="text-muted-foreground">: </span>
                                        <span className="font-semibold tabular-nums">
                                            {formatCurrency(calculatedBalance)}
                                        </span>
                                    </p>
                                </div>
                            )}

                            {isCalculating && (
                                <p className="text-sm text-muted-foreground">Calcolo saldo in corso...</p>
                            )}

                            <div className="space-y-1.5">
                                <Label htmlFor="declared_balance">Saldo reale dichiarato</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="declared_balance"
                                        type="number"
                                        step="0.01"
                                        value={data.declared_balance}
                                        onChange={(e) => setData('declared_balance', e.target.value)}
                                        className="w-48"
                                        placeholder="0,00"
                                    />
                                    <span className="text-sm text-muted-foreground">EUR</span>
                                </div>
                                {errors.declared_balance && (
                                    <p className="text-sm text-destructive">{errors.declared_balance}</p>
                                )}
                            </div>

                            {adjustment !== null && !isNaN(adjustment) && (
                                <div
                                    className={cn(
                                        'rounded-lg border p-3 text-sm',
                                        Math.abs(adjustment) < 0.01
                                            ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/40'
                                            : 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/40',
                                    )}
                                >
                                    <p>
                                        <span className="font-medium">Compensazione: </span>
                                        <span
                                            className={cn(
                                                'font-semibold tabular-nums',
                                                adjustment > 0
                                                    ? 'text-emerald-700 dark:text-emerald-400'
                                                    : adjustment < 0
                                                      ? 'text-red-700 dark:text-red-400'
                                                      : '',
                                            )}
                                        >
                                            {adjustment >= 0 ? '+' : ''}
                                            {formatCurrency(adjustment)}
                                        </span>
                                    </p>
                                    {Math.abs(adjustment) < 0.01 && (
                                        <p className="mt-1 text-xs text-emerald-700 dark:text-emerald-400">
                                            I dati coincidono perfettamente.
                                        </p>
                                    )}
                                </div>
                            )}

                            <div className="space-y-1.5">
                                <Label htmlFor="notes">Note (opzionale)</Label>
                                <Input
                                    id="notes"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Motivo della differenza..."
                                />
                                {errors.notes && <p className="text-sm text-destructive">{errors.notes}</p>}
                            </div>

                            <Button type="submit" disabled={processing || !data.month || !data.declared_balance}>
                                {processing ? 'Salvataggio...' : 'Salva riconciliazione'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {reconciliations.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Riconciliazioni {year}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Mese</TableHead>
                                        <TableHead className="text-right">Saldo calcolato</TableHead>
                                        <TableHead className="text-right">Saldo dichiarato</TableHead>
                                        <TableHead className="text-right">Compensazione</TableHead>
                                        <TableHead>Note</TableHead>
                                        <TableHead className="w-12" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {reconciliations.map((r) => {
                                        const adj = parseFloat(r.adjustment);
                                        return (
                                            <TableRow key={r.id}>
                                                <TableCell className="font-medium">
                                                    {ITALIAN_MONTHS[r.month - 1]}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatCurrency(parseFloat(r.calculated_balance))}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatCurrency(parseFloat(r.declared_balance))}
                                                </TableCell>
                                                <TableCell
                                                    className={cn(
                                                        'text-right font-medium tabular-nums',
                                                        adj > 0
                                                            ? 'text-emerald-600 dark:text-emerald-400'
                                                            : adj < 0
                                                              ? 'text-red-600 dark:text-red-400'
                                                              : '',
                                                    )}
                                                >
                                                    {adj >= 0 ? '+' : ''}
                                                    {formatCurrency(adj)}
                                                </TableCell>
                                                <TableCell className="max-w-48 truncate text-sm text-muted-foreground">
                                                    {r.notes ?? '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <button
                                                        onClick={() => handleDelete(r.id)}
                                                        className="rounded p-1 text-muted-foreground hover:text-destructive"
                                                        aria-label="Elimina"
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </button>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
