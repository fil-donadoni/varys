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
    limiteFatturato: number;
}

const ITALIAN_MONTHS_SHORT = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];

function VarianceBadge({ budget, actual }: { budget: number; actual: number }) {
    const variance = actual - budget;
    const isPositive = variance >= 0;
    return (
        <Badge
            className={
                isPositive
                    ? 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300'
                    : 'border-transparent bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'
            }
        >
            {isPositive ? '+' : ''}
            {formatCurrency(variance)}
        </Badge>
    );
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
    payload?: Array<{ name: string; value: number; color: string }>;
    label?: string;
}) {
    if (!active || !payload?.length) return null;
    return (
        <div className="rounded-lg border bg-card px-3 py-2 text-sm shadow-md">
            <p className="mb-1 font-medium text-foreground">{label}</p>
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
    limiteFatturato,
}: DashboardProps) {
    const incomeCategories = categoryData.filter((c) => c.type === 'income');
    const expenseCategories = categoryData.filter((c) => c.type === 'expense');

    const totalBudgetIncome = monthlyData.reduce((s, m) => s + m.budget_income, 0);
    const totalBudgetExpense = monthlyData.reduce((s, m) => s + m.budget_expense, 0);
    const actualMonths = monthlyData.filter((m) => m.has_actual);
    const totalActualIncome = actualMonths.reduce((s, m) => s + m.actual_income, 0);
    const totalActualExpense = actualMonths.reduce((s, m) => s + m.actual_expense, 0);
    const netBalance = totalActualIncome - totalActualExpense;
    const savingsRate =
        totalActualIncome > 0 ? ((totalActualIncome - totalActualExpense) / totalActualIncome) * 100 : 0;

    const barChartData = monthlyData.map((m) => ({
        month: ITALIAN_MONTHS_SHORT[m.month - 1],
        'Entrate Budget': m.budget_income,
        'Entrate Consuntivo': m.has_actual ? m.actual_income : null,
        'Uscite Budget': m.budget_expense,
        'Uscite Consuntivo': m.has_actual ? m.actual_expense : null,
    }));

    const lineChartData = monthlyData.map((m) => ({
        month: ITALIAN_MONTHS_SHORT[m.month - 1],
        'Saldo Budget': m.cumulative_budget,
        'Saldo Reale': m.cumulative_actual,
    }));

    function navigateYear(delta: number) {
        router.get('/', { year: year + delta }, { preserveState: true });
    }

    const invoicePercentage = limiteFatturato > 0 ? (invoicedBudgetTotal / limiteFatturato) * 100 : 0;
    const isOverLimit = invoicedBudgetTotal > limiteFatturato && limiteFatturato > 0;

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
                {limiteFatturato > 0 && (
                    <div className="rounded-lg border bg-card p-4 shadow-xs">
                        <div className="flex items-center justify-between text-sm">
                            <span className="font-medium">Fatturato budget</span>
                            <span
                                className={cn(
                                    'font-semibold tabular-nums',
                                    isOverLimit ? 'text-destructive' : 'text-foreground',
                                )}
                            >
                                {formatCurrency(invoicedBudgetTotal)} / {formatCurrency(limiteFatturato)}
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
                                Superato del {formatCurrency(invoicedBudgetTotal - limiteFatturato)}
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
                                <p className="text-2xl font-bold tabular-nums">{formatCurrency(totalActualIncome)}</p>
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <span>Budget: {formatCurrency(totalBudgetIncome)}</span>
                                    {actualMonths.length > 0 && (
                                        <VarianceBadge budget={totalBudgetIncome} actual={totalActualIncome} />
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    Uscite Totali
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-1">
                                <p className="text-2xl font-bold tabular-nums">{formatCurrency(totalActualExpense)}</p>
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    <span>Budget: {formatCurrency(totalBudgetExpense)}</span>
                                    {actualMonths.length > 0 && (
                                        <VarianceBadge budget={totalBudgetExpense} actual={totalActualExpense} />
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-sm font-medium text-muted-foreground">Saldo Netto</CardTitle>
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
                                <p className="text-xs text-muted-foreground">Entrate − Uscite consuntivo</p>
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
                                <Bar
                                    dataKey="Entrate Consuntivo"
                                    fill="#10b981"
                                    fillOpacity={1}
                                    radius={[3, 3, 0, 0]}
                                />
                                <Bar dataKey="Uscite Budget" fill="#f87171" fillOpacity={0.45} radius={[3, 3, 0, 0]} />
                                <Bar dataKey="Uscite Consuntivo" fill="#ef4444" fillOpacity={1} radius={[3, 3, 0, 0]} />
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
