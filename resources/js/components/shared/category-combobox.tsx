import { Ban, Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { categoriesFor, type ImportCategory } from '@/lib/bank-import';
import { cn } from '@/lib/utils';

interface CategoryComboboxProps {
    categories: ImportCategory[];
    /** Signed bank amount: decides which categories fit. */
    amount: number;
    value: number | null;
    excluded: boolean;
    disabled?: boolean;
    ariaLabel: string;
    onChange: (categoryId: number | null, exclude: boolean) => void;
    /** Opens the "new category" dialog, prefilled with what the user was searching. */
    onCreate?: (name: string) => void;
}

function ColorDot({ color }: { color: string | null }) {
    return (
        <span
            className="inline-block size-2 shrink-0 rounded-full border"
            style={{ backgroundColor: color ?? 'transparent' }}
            aria-hidden="true"
        />
    );
}

export function CategoryCombobox({
    categories,
    amount,
    value,
    excluded,
    disabled,
    ariaLabel,
    onChange,
    onCreate,
}: CategoryComboboxProps) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const options = categoriesFor(amount, categories);
    const selected = categories.find((c) => c.id === value);

    function choose(categoryId: number | null, exclude: boolean) {
        setOpen(false);
        setSearch('');
        onChange(categoryId, exclude);
    }

    function create() {
        setOpen(false);
        onCreate?.(search.trim());
        setSearch('');
    }

    const groups = [
        { heading: 'Entrate', items: options.filter((c) => c.type === 'income') },
        { heading: 'Uscite', items: options.filter((c) => c.type === 'expense') },
    ].filter((group) => group.items.length > 0);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-label={ariaLabel}
                    disabled={disabled}
                    className="h-7 w-52 justify-between px-2 text-xs font-normal"
                >
                    <span
                        className={cn(
                            'flex min-w-0 items-center gap-1.5',
                            !selected && !excluded && 'text-muted-foreground',
                        )}
                    >
                        {excluded ? (
                            <>
                                <Ban className="size-3" /> Escluso
                            </>
                        ) : selected ? (
                            <>
                                <ColorDot color={selected.color} />
                                <span className="truncate">{selected.name}</span>
                            </>
                        ) : (
                            'Scegli categoria…'
                        )}
                    </span>
                    <ChevronsUpDown className="size-3 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-64 p-0" align="start">
                {/* Plain substring search: fuzzy matching finds "Fondo pensione" for "spes". */}
                <Command
                    filter={(value, search, keywords) =>
                        [value, ...(keywords ?? [])].join(' ').toLowerCase().includes(search.trim().toLowerCase())
                            ? 1
                            : 0
                    }
                >
                    <CommandInput placeholder="Cerca categoria…" value={search} onValueChange={setSearch} />
                    <CommandList>
                        <CommandEmpty>Nessuna categoria trovata.</CommandEmpty>
                        {groups.map((group) => (
                            <CommandGroup key={group.heading} heading={group.heading}>
                                {group.items.map((category) => (
                                    <CommandItem
                                        key={category.id}
                                        value={`${category.name}__${category.id}`}
                                        keywords={[category.name]}
                                        onSelect={() => choose(category.id, false)}
                                    >
                                        <ColorDot color={category.color} />
                                        <span className="flex-1 truncate">{category.name}</span>
                                        {!excluded && value === category.id && <Check className="size-3.5" />}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ))}
                        <CommandSeparator />
                        <CommandGroup forceMount>
                            {onCreate && (
                                <CommandItem value="__create" forceMount onSelect={create}>
                                    <Plus />
                                    {search.trim() !== '' ? `Crea "${search.trim()}"…` : 'Nuova categoria…'}
                                </CommandItem>
                            )}
                            <CommandItem
                                value="__exclude"
                                keywords={['escludi', 'giroconto', 'finanziamento']}
                                onSelect={() => choose(null, true)}
                            >
                                <Ban />
                                Escludi (giroconto, finanziamento…)
                            </CommandItem>
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
