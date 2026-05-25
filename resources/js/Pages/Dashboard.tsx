import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { formatQty, rupiah } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface Warehouse {
    id: number;
    name: string;
}

interface Stats {
    today: { total: number; count: number };
    month: { total: number; count: number; aov: number };
}

interface TrendPoint {
    date: string;
    total: number;
}

interface TopProduct {
    id: number;
    name: string;
    sku: string;
    omzet: number;
    qty: number;
}

interface LowStockRow {
    product_id: number;
    product_name: string;
    sku: string;
    warehouse_name: string;
    qty: number;
    min_stock: number;
}

interface ApDueRow {
    id: number;
    ap_no: string;
    supplier_name: string;
    due_date: string | null;
    amount: number;
    paid_amount: number;
    remaining: number;
    is_overdue: boolean;
}

interface Props {
    filters: { warehouse_id: number | null; can_view_all: boolean };
    warehouses: Warehouse[];
    stats: Stats;
    trend: TrendPoint[];
    top_products: TopProduct[];
    low_stock: LowStockRow[];
    ap_due: ApDueRow[];
    can: { view_inventory: boolean; view_ap: boolean };
}

function formatShortDate(iso: string): string {
    const d = new Date(iso + 'T00:00:00');
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' });
}

export default function Dashboard({
    filters,
    warehouses,
    stats,
    trend,
    top_products,
    low_stock,
    ap_due,
    can,
}: Props) {
    const handleWarehouseChange = (value: string) => {
        const params: Record<string, string> = {};
        if (value !== '') params.warehouse_id = value;
        router.get(route('dashboard'), params, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const summaryCards = [
        { label: 'Omzet Hari Ini', value: rupiah(stats.today.total) },
        { label: 'Omzet Bulan Ini', value: rupiah(stats.month.total) },
        {
            label: 'Jumlah Transaksi',
            value: `${formatQty(stats.today.count, 0)} hari · ${formatQty(stats.month.count, 0)} bulan`,
        },
        { label: 'Rata-rata Transaksi', value: rupiah(stats.month.aov) },
    ];

    const trendChartData = trend.map((p) => ({
        ...p,
        label: formatShortDate(p.date),
    }));

    const topProductChartData = top_products.map((p) => ({
        name: p.name.length > 18 ? p.name.slice(0, 18) + '…' : p.name,
        omzet: p.omzet,
    }));

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-gray-800">Dashboard</h2>}
        >
            <Head title="Dashboard" />

            <div className="mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
                {/* Filter Cabang */}
                {filters.can_view_all && (
                    <div className="flex flex-wrap items-center gap-3">
                        <label
                            htmlFor="warehouse-filter"
                            className="text-sm font-medium text-gray-700"
                        >
                            Cabang:
                        </label>
                        <select
                            id="warehouse-filter"
                            value={filters.warehouse_id ?? ''}
                            onChange={(e) => handleWarehouseChange(e.target.value)}
                            className="rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">Semua Cabang</option>
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </select>
                    </div>
                )}

                {/* Kartu ringkas */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {summaryCards.map((c) => (
                        <Card key={c.label}>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm font-medium text-muted-foreground">
                                    {c.label}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold leading-tight">
                                    {c.value}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Grafik */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base font-semibold">
                                Tren Penjualan 30 Hari
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64 w-full">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart
                                        data={trendChartData}
                                        margin={{ top: 8, right: 16, left: 0, bottom: 0 }}
                                    >
                                        <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                                        <XAxis
                                            dataKey="label"
                                            interval="preserveStartEnd"
                                            minTickGap={24}
                                            tick={{ fontSize: 11 }}
                                        />
                                        <YAxis
                                            tick={{ fontSize: 11 }}
                                            tickFormatter={(v: number) =>
                                                v >= 1_000_000
                                                    ? `${(v / 1_000_000).toFixed(1)}jt`
                                                    : v >= 1_000
                                                      ? `${Math.round(v / 1_000)}rb`
                                                      : `${v}`
                                            }
                                            width={50}
                                        />
                                        <Tooltip
                                            formatter={(v) => rupiah(Number(v ?? 0))}
                                            labelFormatter={(l) => `Tanggal ${l}`}
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="total"
                                            stroke="#4f46e5"
                                            strokeWidth={2}
                                            dot={false}
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base font-semibold">
                                Top 5 Produk (by Omzet, Bulan Ini)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {top_products.length === 0 ? (
                                <div className="flex h-64 items-center justify-center text-sm text-muted-foreground">
                                    Belum ada penjualan bulan ini.
                                </div>
                            ) : (
                                <div className="h-64 w-full">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart
                                            data={topProductChartData}
                                            layout="vertical"
                                            margin={{ top: 8, right: 16, left: 0, bottom: 0 }}
                                        >
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                stroke="#e5e7eb"
                                                horizontal={false}
                                            />
                                            <XAxis
                                                type="number"
                                                tick={{ fontSize: 11 }}
                                                tickFormatter={(v: number) =>
                                                    v >= 1_000_000
                                                        ? `${(v / 1_000_000).toFixed(1)}jt`
                                                        : v >= 1_000
                                                          ? `${Math.round(v / 1_000)}rb`
                                                          : `${v}`
                                                }
                                            />
                                            <YAxis
                                                type="category"
                                                dataKey="name"
                                                tick={{ fontSize: 11 }}
                                                width={120}
                                            />
                                            <Tooltip
                                                formatter={(v) => rupiah(Number(v ?? 0))}
                                                labelFormatter={(l) => String(l)}
                                            />
                                            <Bar dataKey="omzet" fill="#10b981" radius={[0, 4, 4, 0]}>
                                                {topProductChartData.map((_, i) => (
                                                    <Cell key={i} fill="#10b981" />
                                                ))}
                                            </Bar>
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Alerts */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {can.view_inventory && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base font-semibold">
                                    Stok Menipis
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {low_stock.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Tidak ada produk yang menyentuh stok minimum.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-gray-100">
                                        {low_stock.map((row) => (
                                            <li
                                                key={`${row.product_id}-${row.warehouse_name}`}
                                                className="flex items-start justify-between py-2"
                                            >
                                                <div className="min-w-0 pr-2">
                                                    <p className="truncate text-sm font-medium text-gray-900">
                                                        {row.product_name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {row.sku} · {row.warehouse_name}
                                                    </p>
                                                </div>
                                                <div className="shrink-0 text-right text-xs">
                                                    <Badge variant="destructive">
                                                        {formatQty(row.qty)} / min {formatQty(row.min_stock)}
                                                    </Badge>
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {can.view_ap && (
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base font-semibold">
                                    Hutang Supplier Jatuh Tempo
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {ap_due.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Tidak ada AP yang akan jatuh tempo dalam 7 hari ke depan.
                                    </p>
                                ) : (
                                    <ul className="divide-y divide-gray-100">
                                        {ap_due.map((row) => (
                                            <li
                                                key={row.id}
                                                className="flex items-start justify-between py-2"
                                            >
                                                <div className="min-w-0 pr-2">
                                                    <p className="truncate text-sm font-medium text-gray-900">
                                                        {row.supplier_name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {row.ap_no} · jatuh tempo {row.due_date ?? '-'}
                                                    </p>
                                                </div>
                                                <div className="shrink-0 text-right text-xs">
                                                    <div className="font-semibold">
                                                        {rupiah(row.remaining)}
                                                    </div>
                                                    {row.is_overdue ? (
                                                        <Badge variant="destructive">Overdue</Badge>
                                                    ) : (
                                                        <Badge variant="warning">≤ 7 hari</Badge>
                                                    )}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>

                <div>
                    <Link
                        href={route('pos.cashier')}
                        className="inline-flex min-h-touch-lg items-center rounded-md bg-primary px-6 text-base font-semibold text-primary-foreground"
                    >
                        Buka Kasir →
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
