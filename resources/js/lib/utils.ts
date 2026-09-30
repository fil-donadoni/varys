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

/**
 * Reads amounts typed the Italian way ("1.234,50", "1.200") as well as server decimals ("1200.50").
 * Dots are thousands separators when a comma is present or when they split groups of three digits.
 */
export function parseAmount(raw: string): number {
    let normalized = raw.trim();
    if (normalized.includes(',') || /^-?\d{1,3}(\.\d{3})+$/.test(normalized)) {
        normalized = normalized.replace(/\./g, '').replace(',', '.');
    }
    const cleaned = normalized.replace(/[^\d.-]/g, '');
    const val = parseFloat(cleaned);
    return isNaN(val) ? 0 : val;
}

export function formatMonth(month: number): string {
    const date = new Date(2024, month - 1);
    return date.toLocaleDateString('it-IT', { month: 'long' });
}
