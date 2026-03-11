import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

interface Props {
    types: Array<{ value: string; label: string }>;
}

interface FormData {
    name: string;
    type: string;
    color: string;
    sort_order: string;
    is_invoiced: boolean;
}

export default function CreateCategory({ types }: Props) {
    const { data, setData, post, processing, errors } = useForm<FormData>({
        name: '',
        type: '',
        color: '#6366f1',
        sort_order: '0',
        is_invoiced: false,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post('/categories');
    }

    return (
        <AppLayout>
            <Head title="Nuova Categoria" />

            <div className="space-y-6">
                <div className="flex items-center gap-4">
                    <Button variant="ghost" size="icon" asChild aria-label="Torna alle categorie">
                        <Link href="/categories">
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Nuova Categoria</h1>
                        <p className="text-sm text-muted-foreground">
                            Aggiungi una nuova categoria al tuo budget.
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} noValidate>
                    <Card className="max-w-lg">
                        <CardHeader>
                            <CardTitle>Dettagli categoria</CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-5">
                            <div className="space-y-1.5">
                                <Label htmlFor="name">Nome</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    placeholder="es. Stipendio, Affitto…"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    aria-invalid={!!errors.name}
                                    aria-describedby={errors.name ? 'name-error' : undefined}
                                    required
                                />
                                {errors.name && (
                                    <p id="name-error" className="text-sm text-destructive">
                                        {errors.name}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="type">Tipo</Label>
                                <Select
                                    value={data.type}
                                    onValueChange={(value) => setData('type', value)}
                                    required
                                >
                                    <SelectTrigger
                                        id="type"
                                        aria-invalid={!!errors.type}
                                        aria-describedby={errors.type ? 'type-error' : undefined}
                                    >
                                        <SelectValue placeholder="Seleziona tipo" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {types.map((t) => (
                                            <SelectItem key={t.value} value={t.value}>
                                                {t.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.type && (
                                    <p id="type-error" className="text-sm text-destructive">
                                        {errors.type}
                                    </p>
                                )}
                            </div>

                            {data.type === 'income' && (
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="is_invoiced"
                                        checked={data.is_invoiced}
                                        onCheckedChange={(checked) =>
                                            setData('is_invoiced', checked === true)
                                        }
                                    />
                                    <Label htmlFor="is_invoiced" className="cursor-pointer">
                                        Fatturata
                                    </Label>
                                </div>
                            )}

                            <div className="space-y-1.5">
                                <Label htmlFor="color">Colore</Label>
                                <div className="flex items-center gap-3">
                                    <Input
                                        id="color"
                                        type="color"
                                        value={data.color}
                                        onChange={(e) => setData('color', e.target.value)}
                                        className="h-9 w-16 cursor-pointer px-1 py-1"
                                        aria-invalid={!!errors.color}
                                        aria-describedby={errors.color ? 'color-error' : undefined}
                                    />
                                    <span className="font-mono text-sm text-muted-foreground">
                                        {data.color}
                                    </span>
                                </div>
                                {errors.color && (
                                    <p id="color-error" className="text-sm text-destructive">
                                        {errors.color}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="sort_order">Ordine di visualizzazione</Label>
                                <Input
                                    id="sort_order"
                                    type="number"
                                    min={0}
                                    step={1}
                                    value={data.sort_order}
                                    onChange={(e) => setData('sort_order', e.target.value)}
                                    className="w-32"
                                    aria-invalid={!!errors.sort_order}
                                    aria-describedby={
                                        errors.sort_order ? 'sort-order-error' : undefined
                                    }
                                />
                                {errors.sort_order && (
                                    <p id="sort-order-error" className="text-sm text-destructive">
                                        {errors.sort_order}
                                    </p>
                                )}
                            </div>
                        </CardContent>

                        <CardFooter className="gap-3">
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Salvataggio…' : 'Salva categoria'}
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href="/categories">Annulla</Link>
                            </Button>
                        </CardFooter>
                    </Card>
                </form>
            </div>
        </AppLayout>
    );
}
