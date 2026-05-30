import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { rupiah } from '@/lib/utils';

export default function Detail({ sale }: { sale: any }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Invoice {sale.invoice_no}</h2>}
        >
            <Head title={sale.invoice_no} />
            <div className="mx-auto max-w-3xl space-y-4 p-4">
                <div className="flex justify-end">
                    <Link href={route('sales.receipt', sale.id)}>
                        <Button variant="outline" size="sm">Lihat Struk</Button>
                    </Link>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>{sale.invoice_no}</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        <div>Tanggal: {sale.date}</div>
                        <div>Customer: {sale.customer?.name ?? 'Umum'}</div>
                        <hr />
                        {sale.items?.map((it: any) => (
                            <div key={it.id} className="flex justify-between">
                                <span>
                                    {it.product?.name} × {it.qty}
                                </span>
                                <span>{rupiah(it.subtotal)}</span>
                            </div>
                        ))}
                        <hr />
                        <div className="flex justify-between text-lg font-bold">
                            <span>Total</span>
                            <span>{rupiah(sale.total)}</span>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
