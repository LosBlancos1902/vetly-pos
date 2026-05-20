import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Card, CardContent } from '@/Components/ui/card';

interface Row {
    id: number;
    qty: string;
    cost_avg: string;
    product?: { sku: string; name: string };
    warehouse?: { name: string };
}

export default function Stock({ inventories }: { inventories: { data: Row[] } }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Stok</h2>}>
            <Head title="Stok" />
            <div className="mx-auto max-w-7xl p-4">
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Produk</TableHead>
                                    <TableHead>Gudang</TableHead>
                                    <TableHead className="text-right">Qty</TableHead>
                                    <TableHead className="text-right">HPP Rata2</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {inventories.data.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>
                                            {r.product?.name} ({r.product?.sku})
                                        </TableCell>
                                        <TableCell>{r.warehouse?.name}</TableCell>
                                        <TableCell className="text-right">{r.qty}</TableCell>
                                        <TableCell className="text-right">{r.cost_avg}</TableCell>
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
