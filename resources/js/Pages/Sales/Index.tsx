import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Card, CardContent } from '@/Components/ui/card';
import { rupiah } from '@/lib/utils';

interface Sale {
    id: number;
    invoice_no: string;
    date: string;
    total: string;
    status: string;
    customer?: { name: string };
}

export default function Index({ sales }: { sales: { data: Sale[] } }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Penjualan</h2>}>
            <Head title="Penjualan" />
            <div className="mx-auto max-w-7xl p-4">
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Invoice</TableHead>
                                    <TableHead>Tanggal</TableHead>
                                    <TableHead>Customer</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sales.data.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell>
                                            <Link
                                                href={route('sales.show', s.id)}
                                                className="text-primary underline"
                                            >
                                                {s.invoice_no}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{s.date}</TableCell>
                                        <TableCell>{s.customer?.name ?? 'Umum'}</TableCell>
                                        <TableCell>{s.status}</TableCell>
                                        <TableCell className="text-right">
                                            {rupiah(s.total)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
