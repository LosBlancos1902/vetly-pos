import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { formatQty, rupiah } from '@/lib/utils';
import { type FormEvent } from 'react';

interface Row {
    product_id: number;
    sku: string;
    product_name: string;
    type: string;
    warehouse_id: number;
    warehouse_code: string;
    warehouse_name: string;
    qty: number;
    cost_avg: number;
    nilai: number;
}

interface Warehouse {
    id: number;
    code: string;
    name: string;
}

interface Props {
    filters: { warehouse_id: number | null; from: string; to: string; only_with_stock: boolean };
    warehouses: Warehouse[];
    rows: Row[];
    totals: { qty: number; nilai: number };
}

export default function Valuation({ filters, warehouses, rows, totals }: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.inventory_valuation'), params, { preserveScroll: true });
    }

    const exportHref = (() => {
        const p = new URLSearchParams();
        if (filters.warehouse_id) p.set('warehouse_id', String(filters.warehouse_id));
        if (filters.only_with_stock) p.set('only_with_stock', '1');
        p.set('export', '1');
        return `${route('reports.inventory_valuation')}?${p.toString()}`;
    })();

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Nilai Stok (Inventory Valuation)</h2>}
        >
            <Head title="Nilai Stok" />
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
                    <div className="flex items-end gap-2">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="only_with_stock"
                                value="1"
                                defaultChecked={filters.only_with_stock}
                            />
                            Hanya tampilkan yang stoknya &gt; 0
                        </label>
                    </div>
                    <div className="ml-auto flex gap-2">
                        <Button type="submit">Tampilkan</Button>
                        <a
                            href={exportHref}
                            className="inline-flex h-9 items-center rounded border border-gray-300 bg-white px-4 text-sm font-medium hover:bg-gray-50"
                        >
                            Export Excel
                        </a>
                    </div>
                </form>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-gray-500">Total Qty</div>
                            <div className="mt-1 text-lg font-semibold tabular-nums">
                                {formatQty(totals.qty, 4)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-indigo-300 bg-indigo-50 md:col-span-2">
                        <CardContent className="p-4">
                            <div className="text-xs text-indigo-700">Total Nilai Stok</div>
                            <div className="mt-1 text-2xl font-bold tabular-nums text-indigo-900">
                                {rupiah(totals.nilai)}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Cabang</TableHead>
                                    <TableHead>SKU</TableHead>
                                    <TableHead>Produk</TableHead>
                                    <TableHead>Tipe</TableHead>
                                    <TableHead className="text-right">Qty</TableHead>
                                    <TableHead className="text-right">Cost Avg</TableHead>
                                    <TableHead className="text-right">Nilai</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-sm italic text-gray-500">
                                            Tidak ada data.
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
                                        <TableCell className="text-xs text-gray-500">{r.type}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatQty(r.qty, 4)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {rupiah(r.cost_avg)}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {rupiah(r.nilai)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <p className="text-xs italic text-gray-500">
                    Nilai = qty × cost_avg (snapshot saat ini). Untuk nilai stok per tanggal
                    lampau, butuh recompute via stock_movements (belum diimplementasi).
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
