import { Head, router } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn, formatCurrency } from '@/lib/utils';

interface MonthlyData {
    month: number;
    budget_income: number;
    budget_expense: number;
    budget_balance: number;
    actual_income: number;
    actual_expense: number;
    actual_balance: number;
    has_actual: boolean;
    cumulative_budget: number;
    cumulative_actual: number | null;
}

interface CategoryData {
    id: number;
    name: string;
    type: 'income' | 'expense';
    color: string;
    budget_total: number;
    actual_total: number;
}

interface Alert {
    type: 'warning' | 'danger' | 'info';
    message: string;
}

interface DashboardProps {
    year: number;
    monthlyData: MonthlyData[];
    categoryData: CategoryData[];
    alerts: Alert[];
    currentMonth: number;
    invoicedBudgetTotal: number;
    invoiceLimit: number;
    openingBalance: number;
}

const ITALIAN_MONTHS_SHORT = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

function VarianceBadge({
    budget,
    actual,
    type = 'income',
}: {
    budget: number;
    actual: number;
    type?: 'income' | 'expense';
}) {
    const variance = actual - budget;
    const favorable = type === 'income' ? variance >= 0 : variance <= 0;
    return (
        <Badge
            className={
                favorable
                    ? 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
                    : 'border-transparent bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'
            }
        >
            {variance > 0 ? '+' : ''}
            {formatCurrency(variance)}
        </Badge>
    );
}

function round(v: number) {
    return Math.round(v * 100) / 100;
}

function currencyFormatter(value: number) {
    return new Intl.NumberFormat('it-IT', {
        style: 'currency',
        currency: 'EUR',
        maximumFractionDigits: 0,
    }).format(value);
}

function CustomTooltipCurrency({
    active,
    payload,
    label,
}: {
    active?: boolean;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    payload?: Array<{ name: string; value: number; color: string; payload?: any }>;
    label?: string;
}) {
    if (!active || !payload?.length) return null;
    const isEstimated = payload[0]?.payload?.has_actual === false;
    return (
        <div className="rounded-lg border bg-card px-3 py-2 text-sm shadow-md">
            <p className="mb-1 font-medium text-foreground">
                {label}
                {isEstimated && <span className="ml-1.5 text-xs text-muted-foreground">(stimato)</span>}
            </p>
            {payload.map((entry) => (
                <p key={entry.name} style={{ color: entry.color }} className="leading-5">
                    {entry.name}: {formatCurrency(entry.value)}
                </p>
            ))}
        </div>
    );
}

export default function Dashboard({
    year,
    monthlyData,
    categoryData,
    alerts,
    currentMonth,
    invoicedBudgetTotal,
    invoiceLimit,
    openingBalance,
}: DashboardProps) {
    const incomeCategories = categoryData.filter((c) => c.type === 'income');
    const expenseCategories = categoryData.filter((c) => c.type === 'expense');

    const totalBudgetIncome = monthlyData.reduce((s, m) => s + m.budget_income, 0);
    const totalBudgetExpense = monthlyData.reduce((s, m) => s + m.budget_expense, 0);
    const actualMonths = monthlyData.filter((m) => m.has_actual);
    // Effective totals: actual where available, budget as fallback
    const totalEffectiveIncome = monthlyData.reduce(
        (s, m) => s + (m.has_actual ? m.actual_income : m.budget_income),
        0,
    );
    const totalEffectiveExpense = monthlyData.reduce(
        (s, m) => s + (m.has_actual ? m.actual_expense : m.budget_expense),
        0,
    );
    const netBalance = totalEffectiveIncome - totalEffectiveExpense;
    const savingsRate =
        totalEffectiveIncome > 0 ? ((totalEffectiveIncome - totalEffectiveExpense) / totalEffectiveIncome) * 100 : 0;

    const barChartData = monthlyData.map((m) => ({
        month: ITALIAN_MONTHS_SHORT[m.month - 1],
        'Entrate Budget': m.budget_income,
        'Entrate Consuntivo': m.has_actual ? m.actual_income : m.budget_income,
        'Uscite Budget': m.budget_expense,
        'Uscite Consuntivo': m.has_actual ? m.actual_expense : m.budget_expense,
        has_actual: m.has_actual,
    }));

    // Build cumulative line data: actual line uses real data, projected uses budget fallback
    const lastActualMonth = monthlyData.filter((d) => d.has_actual).pop()?.month ?? 0;
    const lineChartData = monthlyData.reduce<
        Array<{
            month: string;
            'Saldo Budget': number;
            'Saldo Reale': number | null;
            'Saldo Proiezione': number | null;
        }>
    >((acc, m, i) => {
        const prevActual = i > 0 ? (acc[i - 1]['Saldo Reale'] ?? 0) : openingBalance;
        const prevProjected = i > 0 ? (acc[i - 1]['Saldo Proiezione'] ?? 0) : openingBalance;
        const cumulActual = m.has_actual ? prevActual + m.actual_balance : 0;
        const cumulProjected = prevProjected + (m.has_actual ? m.actual_balance : m.budget_balance);
        acc.push({
            month: ITALIAN_MONTHS_SHORT[m.month - 1],
            'Saldo Budget': m.cumulative_budget,
            'Saldo Reale': m.has_actual ? round(cumulActual) : null,
            'Saldo Proiezione': m.month >= lastActualMonth ? round(cumulProjected) : null,
        });
        return acc;
    }, []);

    function navigateYear(delta: number) {
        router.get('/', { year: year + delta }, { preserveState: true });
    }

    const invoicePercentage = invoiceLimit > 0 ? (invoicedBudgetTotal / invoiceLimit) * 100 : 0;
    const isOverLimit = invoicedBudgetTotal > invoiceLimit && invoiceLimit > 0;

    const alertStyles: Record<Alert['type'], string> = {
        warning:
            'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
        danger: 'border-red-300 bg-red-50 text-red-800 dark:border-red-700 dark:bg-red-950/40 dark:text-red-300',
        info: 'border-blue-300 bg-blue-50 text-blue-800 dark:border-blue-700 dark:bg-blue-950/40 dark:text-blue-300',
    };

    return (
        <AppLayout>
            <Head title="Dashboard" />

            <div className="space-y-6">
                {/* Year selector */}
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
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

                {/* Alerts */}
                {alerts.length > 0 && (
                    <ul className="space-y-2" role="list" aria-label="Avvisi">
                        {alerts.map((alert, i) => (
                            <li
                                key={i}
                                role="alert"
                                className={`rounded-lg border px-4 py-3 text-sm font-medium ${alertStyles[alert.type]}`}
                            >
                                {alert.message}
                            </li>
                        ))}
                    </ul>
                )}

                {/* Invoice limit progress */}
                {invoiceLimit > 0 && (
                    <div className="rounded-lg border bg-card p-4 shadow-xs">
                        <div className="flex items-center justify-between text-sm">
                            <span className="font-medium">Fatturato budget</span>
                            <span
                                className={cn(
                                    'font-semibold tabular-nums',
                                    isOverLimit ? 'text-destructive' : 'text-foreground',
                                )}
                            >
                                {formatCurrency(invoicedBudgetTotal)} / {formatCurrency(invoiceLimit)}
                            </span>
                        </div>
                        <div className="mt-2 h-2.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className={cn(
                                    'h-full rounded-full transition-all',
                                    isOverLimit
                                        ? 'bg-destructive'
                                        : invoicePercentage > 80
                                          ? 'bg-amber-500'
                                          : 'bg-emerald-500',
                                )}
                                style={{ width: `${Math.min(invoicePercentage, 100)}%` }}
                            />
                        </div>
                        {isOverLimit && (
                            <p className="mt-1.5 text-xs font-medium text-destructive">
                                Superato del {formatCurrency(invoicedBudgetTotal - invoiceLimit)}
                            </p>
                        )}
                    </div>
                )}

                {/* KPI cards */}
                <section aria-label="Riepilogo">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Entrate Totali
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1">
                                <p className="text-2xl font-bold tabular-nums">
                                    {formatCurrency(totalEffectiveIncome)}
                                </p>
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <span>Budget: {formatCurrency(totalBudgetIncome)}</span>
                                    <VarianceBadge budget={totalBudgetIncome} actual={totalEffectiveIncome} />
                                </div>
                                {actualMonths.length < 12 && (
                                    <p className="text-[10px] text-muted-foreground">
                                        {12 - actualMonths.length} mesi stimati da budget
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Uscite Totali
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1">
                                <p className="text-2xl font-bold tabular-nums">
                                    {formatCurrency(totalEffectiveExpense)}
                                </p>
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <span>Budget: {formatCurrency(totalBudgetExpense)}</span>
                                    <VarianceBadge budget={totalBudgetExpense} actual={totalEffectiveExpense} type="expense" />
                                </div>
                                {actualMonths.length < 12 && (
                                    <p className="text-[10px] text-muted-foreground">
                                        {12 - actualMonths.length} mesi stimati da budget
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Saldo Netto a fine anno
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1">
                                <p
                                    className={`text-2xl font-bold tabular-nums ${
                                        netBalance >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                >
                                    {formatCurrency(netBalance)}
                                </p>
                                <p className="text-xs text-muted-foreground">Consuntivo + stime da budget</p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Tasso di Risparmio
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1">
                                <p
                                    className={`text-2xl font-bold tabular-nums ${
                                        savingsRate >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                >
                                    {savingsRate.toFixed(1)}%
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Sul consuntivo ({actualMonths.length} mes{actualMonths.length === 1 ? 'e' : 'i'})
                                </p>
                            </CardContent>
                        </Card>
                    </div>
                </section>

                {/* Income & Expenses by month bar chart */}
                <Card>
                    <CardHeader>
                        <CardTitle>Entrate &amp; Uscite per Mese</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ResponsiveContainer width="100%" height={320}>
                            <BarChart data={barChartData} margin={{ top: 4, right: 8, left: 8, bottom: 4 }}>
                                <defs>
                                    <pattern
                                        id="stripe-green"
                                        width="6"
                                        height="6"
                                        patternUnits="userSpaceOnUse"
                                        patternTransform="rotate(45)"
                                    >
                                        <rect width="6" height="6" fill="#10b981" fillOpacity={0.2} />
                                        <line
                                            x1="0"
                                            y1="0"
                                            x2="0"
                                            y2="6"
                                            stroke="#10b981"
                                            strokeWidth="2"
                                            strokeOpacity={0.5}
                                        />
                                    </pattern>
                                    <pattern
                                        id="stripe-red"
                                        width="6"
                                        height="6"
                                        patternUnits="userSpaceOnUse"
                                        patternTransform="rotate(45)"
                                    >
                                        <rect width="6" height="6" fill="#ef4444" fillOpacity={0.2} />
                                        <line
                                            x1="0"
                                            y1="0"
                                            x2="0"
                                            y2="6"
                                            stroke="#ef4444"
                                            strokeWidth="2"
                                            strokeOpacity={0.5}
                                        />
                                    </pattern>
                                </defs>
                                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                                <XAxis
                                    dataKey="month"
                                    tick={{ fontSize: 12, fill: 'var(--muted-foreground)' }}
                                    axisLine={{ stroke: 'var(--border)' }}
                                    tickLine={false}
                                />
                                <YAxis
                                    tickFormatter={currencyFormatter}
                                    tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                                    axisLine={false}
                                    tickLine={false}
                                    width={80}
                                />
                                <Tooltip content={<CustomTooltipCurrency />} />
                                <Legend wrapperStyle={{ fontSize: '12px', color: 'var(--muted-foreground)' }} />
                                <Bar dataKey="Entrate Budget" fill="#34d399" fillOpacity={0.45} radius={[3, 3, 0, 0]} />
                                <Bar dataKey="Entrate Consuntivo" radius={[3, 3, 0, 0]}>
                                    {barChartData.map((entry, i) => (
                                        <Cell key={i} fill={entry.has_actual ? '#10b981' : 'url(#stripe-green)'} />
                                    ))}
                                </Bar>
                                <Bar dataKey="Uscite Budget" fill="#f87171" fillOpacity={0.45} radius={[3, 3, 0, 0]} />
                                <Bar dataKey="Uscite Consuntivo" radius={[3, 3, 0, 0]}>
                                    {barChartData.map((entry, i) => (
                                        <Cell key={i} fill={entry.has_actual ? '#ef4444' : 'url(#stripe-red)'} />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </CardContent>
                </Card>

                {/* Balance trend line chart */}
                <Card>
                    <CardHeader>
                        <CardTitle>Andamento Saldo Cumulativo</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ResponsiveContainer width="100%" height={280}>
                            <LineChart data={lineChartData} margin={{ top: 4, right: 8, left: 8, bottom: 4 }}>
                                <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" />
                                <XAxis
                                    dataKey="month"
                                    tick={{ fontSize: 12, fill: 'var(--muted-foreground)' }}
                                    axisLine={{ stroke: 'var(--border)' }}
                                    tickLine={false}
                                />
                                <YAxis
                                    tickFormatter={currencyFormatter}
                                    tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                                    axisLine={false}
                                    tickLine={false}
                                    width={80}
                                />
                                <Tooltip content={<CustomTooltipCurrency />} />
                                <Legend wrapperStyle={{ fontSize: '12px', color: 'var(--muted-foreground)' }} />
                                <Line
                                    type="monotone"
                                    dataKey="Saldo Budget"
                                    stroke="#94a3b8"
                                    strokeWidth={2}
                                    strokeDasharray="6 3"
                                    dot={false}
                                    connectNulls
                                />
                                <Line
                                    type="monotone"
                                    dataKey="Saldo Reale"
                                    stroke="#6366f1"
                                    strokeWidth={2.5}
                                    dot={{ r: 3, fill: '#6366f1', strokeWidth: 0 }}
                                    activeDot={{ r: 5 }}
                                    connectNulls={false}
                                />
                                <Line
                                    type="monotone"
                                    dataKey="Saldo Proiezione"
                                    stroke="#6366f1"
                                    strokeWidth={2}
                                    strokeDasharray="4 4"
                                    strokeOpacity={0.5}
                                    dot={{ r: 2, fill: '#6366f1', strokeWidth: 0, fillOpacity: 0.5 }}
                                    connectNulls
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    </CardContent>
                </Card>

                {/* Category breakdown */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Income categories */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Categorie Entrate</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {incomeCategories.length === 0 ? (
                                <p className="text-sm text-muted-foreground">Nessuna categoria.</p>
                            ) : (
                                <ResponsiveContainer width="100%" height={Math.max(160, incomeCategories.length * 52)}>
                                    <BarChart
                                        data={incomeCategories}
                                        layout="vertical"
                                        margin={{ top: 0, right: 12, left: 8, bottom: 0 }}
                                    >
                                        <CartesianGrid
                                            strokeDasharray="3 3"
                                            horizontal={false}
                                            stroke="var(--border)"
                                        />
                                        <XAxis
                                            type="number"
                                            tickFormatter={currencyFormatter}
                                            tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                                            axisLine={false}
                                            tickLine={false}
                                        />
                                        <YAxis
                                            type="category"
                                            dataKey="name"
                                            tick={{ fontSize: 12, fill: 'var(--foreground)' }}
                                            axisLine={false}
                                            tickLine={false}
                                            width={100}
                                        />
                                        <Tooltip content={<CustomTooltipCurrency />} />
                                        <Legend wrapperStyle={{ fontSize: '12px', color: 'var(--muted-foreground)' }} />
                                        <Bar
                                            dataKey="budget_total"
                                            name="Budget"
                                            fillOpacity={0.4}
                                            radius={[0, 3, 3, 0]}
                                        >
                                            {incomeCategories.map((cat) => (
                                                <Cell key={cat.id} fill={cat.color} />
                                            ))}
                                        </Bar>
                                        <Bar
                                            dataKey="actual_total"
                                            name="Consuntivo"
                                            fillOpacity={1}
                                            radius={[0, 3, 3, 0]}
                                        >
                                            {incomeCategories.map((cat) => (
                                                <Cell key={cat.id} fill={cat.color} />
                                            ))}
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            )}
                        </CardContent>
                    </Card>

                    {/* Expense categories */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Categorie Uscite</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {expenseCategories.length === 0 ? (
                                <p className="text-sm text-muted-foreground">Nessuna categoria.</p>
                            ) : (
                                <ResponsiveContainer width="100%" height={Math.max(160, expenseCategories.length * 52)}>
                                    <BarChart
                                        data={expenseCategories}
                                        layout="vertical"
                                        margin={{ top: 0, right: 12, left: 8, bottom: 0 }}
                                    >
                                        <CartesianGrid
                                            strokeDasharray="3 3"
                                            horizontal={false}
                                            stroke="var(--border)"
                                        />
                                        <XAxis
                                            type="number"
                                            tickFormatter={currencyFormatter}
                                            tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                                            axisLine={false}
                                            tickLine={false}
                                        />
                                        <YAxis
                                            type="category"
                                            dataKey="name"
                                            tick={{ fontSize: 12, fill: 'var(--foreground)' }}
                                            axisLine={false}
                                            tickLine={false}
                                            width={100}
                                        />
                                        <Tooltip content={<CustomTooltipCurrency />} />
                                        <Legend wrapperStyle={{ fontSize: '12px', color: 'var(--muted-foreground)' }} />
                                        <Bar
                                            dataKey="budget_total"
                                            name="Budget"
                                            fillOpacity={0.4}
                                            radius={[0, 3, 3, 0]}
                                        >
                                            {expenseCategories.map((cat) => (
                                                <Cell key={cat.id} fill={cat.color} />
                                            ))}
                                        </Bar>
                                        <Bar
                                            dataKey="actual_total"
                                            name="Consuntivo"
                                            fillOpacity={1}
                                            radius={[0, 3, 3, 0]}
                                        >
                                            {expenseCategories.map((cat) => (
                                                <Cell key={cat.id} fill={cat.color} />
                                            ))}
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
