import { useCallback, useEffect, useState } from 'react';

type Appearance = 'light' | 'dark' | 'system';

function getMediaPreference(): 'light' | 'dark' {
    if (typeof window === 'undefined') {
        return 'light';
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function getCookieValue(name: string): string | null {
    if (typeof document === 'undefined') {
        return null;
    }
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
}

function setCookie(name: string, value: string, days = 365): void {
    if (typeof document === 'undefined') {
        return;
    }
    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
}

function applyTheme(appearance: Appearance): void {
    const isDark = appearance === 'dark' || (appearance === 'system' && getMediaPreference() === 'dark');

    document.documentElement.classList.toggle('dark', isDark);
}

export function initializeTheme(): void {
    const appearance = (getCookieValue('appearance') as Appearance) || 'system';
    applyTheme(appearance);
}

export function useAppearance() {
    const [appearance, setAppearanceState] = useState<Appearance>('system');

    useEffect(() => {
        const saved = getCookieValue('appearance') as Appearance | null;
        if (saved) {
            setAppearanceState(saved);
        }

        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        const handleChange = () => {
            const current = (getCookieValue('appearance') as Appearance) || 'system';
            applyTheme(current);
        };
        mediaQuery.addEventListener('change', handleChange);
        return () => mediaQuery.removeEventListener('change', handleChange);
    }, []);

    const updateAppearance = useCallback((value: Appearance) => {
        setAppearanceState(value);
        setCookie('appearance', value);
        applyTheme(value);
    }, []);

    return { appearance, updateAppearance } as const;
}
