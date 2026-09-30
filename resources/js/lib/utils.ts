import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function formatCurrency(amount: number): string {
    return new Intl.NumberFormat('it-IT', {
        style: 'currency',
        currency: 'EUR',
        useGrouping: 'always',
    }).format(amount);
}

export function parseAmount(raw: string): number {
    const cleaned = raw.replace(',', '.').replace(/[^\d.-]/g, '');
    const val = parseFloat(cleaned);
    return isNaN(val) ? 0 : val;
}

export function formatMonth(month: number): string {
    const date = new Date(2024, month - 1);
    return date.toLocaleDateString('it-IT', { month: 'long' });
}
