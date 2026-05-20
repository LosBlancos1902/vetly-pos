import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { rupiah } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';

interface Stats {
    sales_today_count: number;
    sales_today_total: number;
    product_count: number;
    low_stock: number;
}

export default function Dashboard({ stats }: { stats: Stats }) {
    const cards = [
        { label: 'Transaksi Hari Ini', value: stats.sales_today_count },
        { label: 'Omzet Hari Ini', value: rupiah(stats.sales_today_total) },
        { label: 'Total Produk', value: stats.product_count },
        { label: 'Stok Menipis', value: stats.low_stock },
    ];

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold text-gray-800">Dashboard</h2>}
        >
            <Head title="Dashboard" />

            <div className="mx-auto max-w-7xl p-4 sm:p-6 lg:p-8">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map((c) => (
                        <Card key={c.label}>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-base text-muted-foreground">
                                    {c.label}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-3xl font-bold">{c.value}</div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="mt-6">
                    <Link
                        href={route('pos.cashier')}
                        className="inline-flex min-h-touch-lg items-center rounded-md bg-primary px-8 text-lg font-semibold text-primary-foreground"
                    >
                        Buka Kasir →
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
