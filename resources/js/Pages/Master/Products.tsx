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
import { rupiah } from '@/lib/utils';

interface Product {
    id: number;
    sku: string;
    barcode: string | null;
    name: string;
    price: string;
    category?: { name: string };
    brand?: { name: string };
}

export default function Products({ products }: { products: { data: Product[] } }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Produk</h2>}>
            <Head title="Produk" />
            <div className="mx-auto max-w-7xl p-4">
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>SKU</TableHead>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Kategori</TableHead>
                                    <TableHead className="text-right">Harga</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {products.data.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell>{p.sku}</TableCell>
                                        <TableCell>{p.name}</TableCell>
                                        <TableCell>{p.category?.name ?? '-'}</TableCell>
                                        <TableCell className="text-right">
                                            {rupiah(p.price)}
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
