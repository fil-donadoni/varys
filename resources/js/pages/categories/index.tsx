import { Head, Link, router } from '@inertiajs/react';
import { Check, Pencil, Plus, Trash2 } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface Category {
    id: number;
    name: string;
    type: 'income' | 'expense';
    is_invoiced: boolean;
    color: string | null;
    sort_order: number;
}

interface Props {
    categories: Category[];
    types: Array<{ value: string; label: string }>;
}

const TYPE_LABEL: Record<string, string> = {
    income: 'Entrata',
    expense: 'Uscita',
};

export default function CategoriesIndex({ categories, types }: Props) {
    const income = categories.filter((c) => c.type === 'income');
    const expense = categories.filter((c) => c.type === 'expense');

    function handleDelete(category: Category) {
        if (!confirm(`Eliminare la categoria "${category.name}"?`)) return;
        router.delete(`/categories/${category.id}`);
    }

    function renderGroup(title: string, items: Category[], isIncome = false) {
        if (items.length === 0) return null;

        return (
            <section aria-labelledby={`group-${title}`} className="space-y-2">
                <h2
                    id={`group-${title}`}
                    className="text-sm font-semibold uppercase tracking-wide text-muted-foreground"
                >
                    {title}
                </h2>
                <div className="rounded-lg border bg-card shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-12">Colore</TableHead>
                                <TableHead>Nome</TableHead>
                                <TableHead>Tipo</TableHead>
                                {isIncome && <TableHead className="w-24 text-center">Fatturata</TableHead>}
                                <TableHead className="w-32 text-right">Ordine</TableHead>
                                <TableHead className="w-28 text-right">Azioni</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((category) => (
                                <TableRow key={category.id}>
                                    <TableCell>
                                        <span
                                            className="inline-block size-4 rounded-full border border-border"
                                            style={{
                                                backgroundColor: category.color ?? '#e5e7eb',
                                            }}
                                            aria-label={`Colore: ${category.color ?? 'nessuno'}`}
                                        />
                                    </TableCell>
                                    <TableCell className="font-medium">{category.name}</TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                category.type === 'income'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {TYPE_LABEL[category.type] ?? category.type}
                                        </Badge>
                                    </TableCell>
                                    {isIncome && (
                                        <TableCell className="text-center">
                                            {category.is_invoiced && (
                                                <Check className="mx-auto size-4 text-emerald-600" />
                                            )}
                                        </TableCell>
                                    )}
                                    <TableCell className="text-right tabular-nums text-muted-foreground">
                                        {category.sort_order}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <div className="flex items-center justify-end gap-1">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                asChild
                                                aria-label={`Modifica ${category.name}`}
                                            >
                                                <Link href={`/categories/${category.id}/edit`}>
                                                    <Pencil />
                                                </Link>
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-destructive hover:text-destructive"
                                                aria-label={`Elimina ${category.name}`}
                                                onClick={() => handleDelete(category)}
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </section>
        );
    }

    return (
        <AppLayout>
            <Head title="Categorie" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Categorie</h1>
                        <p className="text-sm text-muted-foreground">
                            Gestisci le categorie di entrate e uscite.
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/categories/create">
                            <Plus />
                            Nuova Categoria
                        </Link>
                    </Button>
                </div>

                {categories.length === 0 ? (
                    <div className="rounded-lg border border-dashed bg-card p-12 text-center">
                        <p className="text-muted-foreground">Nessuna categoria presente.</p>
                        <Button asChild className="mt-4">
                            <Link href="/categories/create">
                                <Plus />
                                Nuova Categoria
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <>
                        {renderGroup('Entrate', income, true)}
                        {renderGroup('Uscite', expense)}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
