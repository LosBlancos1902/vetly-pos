import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { formatQty } from '@/lib/utils';
import { type FormEvent } from 'react';
import ExportButton from '../_components/ExportButton';
import { ColumnOption } from '../_components/ExportColumnPickerModal';

interface Row {
    product_id: number;
    sku: string;
    product_name: string;
    warehouse_id: number;
    warehouse_code: string;
    warehouse_name: string;
    qty: number;
    min_stock: number;
    max_stock: number | null;
    shortage: number;
}

interface Warehouse {
    id: number;
    code: string;
    name: string;
}

interface Props {
    filters: { warehouse_id: number | null };
    warehouses: Warehouse[];
    rows: Row[];
    available_columns: ColumnOption[];
}

export default function MinStock({ filters, warehouses, rows, available_columns }: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.inventory_min_stock'), params, { preserveScroll: true });
    }


    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Stok Minimum (Alert Reorder)</h2>}
        >
            <Head title="Stok Minimum" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
                >
                    {warehouses.length > 1 && (
                        <div>
                            <Label htmlFor="warehouse_id">Cabang</Label>
                            <select
                                id="warehouse_id"
                                name="warehouse_id"
                                defaultValue={filters.warehouse_id ?? ''}
                                className="block h-9 rounded border border-gray-300 bg-white px-2 text-sm"
                            >
                                <option value="">— Semua / Konsolidasi —</option>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.code} — {w.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div className="ml-auto flex gap-2">
                        <Button type="submit">Tampilkan</Button>
                        <ExportButton
                            baseUrl={route('reports.inventory_min_stock')}
                            params={{ warehouse_id: filters.warehouse_id }}
                            columns={available_columns}
                        />
                    </div>
                </form>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Cabang</TableHead>
                                    <TableHead>SKU</TableHead>
                                    <TableHead>Produk</TableHead>
                                    <TableHead className="text-right">Qty Sekarang</TableHead>
                                    <TableHead className="text-right">Min Stock</TableHead>
                                    <TableHead className="text-right">Max Stock</TableHead>
                                    <TableHead className="text-right">Kekurangan</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-center text-sm italic text-gray-500">
                                            Semua produk masih di atas minimum.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((r) => (
                                    <TableRow key={`${r.warehouse_id}-${r.product_id}`}>
                                        <TableCell>
                                            <span className="font-mono text-xs">{r.warehouse_code}</span>{' '}
                                            {r.warehouse_name}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{r.sku}</TableCell>
                                        <TableCell>{r.product_name}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatQty(r.qty, 4)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatQty(r.min_stock, 4)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {r.max_stock !== null ? formatQty(r.max_stock, 4) : '-'}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums text-red-700">
                                            {formatQty(r.shortage, 4)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={r.qty <= 0 ? 'destructive' : 'warning'}>
                                                {r.qty <= 0 ? 'HABIS' : 'di bawah minimum'}
                                            </Badge>
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
