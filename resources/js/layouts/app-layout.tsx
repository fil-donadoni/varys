import { Link, usePage } from '@inertiajs/react';
import { BarChart3, DollarSign, FolderOpen, Receipt } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface AppLayoutProps {
    children: ReactNode;
}

const navigation = [
    { name: 'Dashboard', href: '/', icon: BarChart3 },
    { name: 'Budget', href: '/budget', icon: DollarSign },
    { name: 'Consuntivo', href: '/actual', icon: Receipt },
    { name: 'Categorie', href: '/categories', icon: FolderOpen },
];

export default function AppLayout({ children }: AppLayoutProps) {
    const { url } = usePage();

    return (
        <div className="min-h-screen bg-background">
            <nav className="border-b bg-card">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 items-center justify-between">
                        <div className="flex items-center gap-8">
                            <Link href="/" className="text-xl font-bold text-primary">
                                Varys
                            </Link>
                            <div className="flex items-center gap-1">
                                {navigation.map((item) => {
                                    const isActive = item.href === '/' ? url === '/' : url.startsWith(item.href);
                                    return (
                                        <Link
                                            key={item.name}
                                            href={item.href}
                                            className={cn(
                                                'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                                                isActive
                                                    ? 'bg-primary/10 text-primary'
                                                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                                            )}
                                        >
                                            <item.icon className="h-4 w-4" />
                                            {item.name}
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                </div>
            </nav>

            <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">{children}</main>
        </div>
    );
}
