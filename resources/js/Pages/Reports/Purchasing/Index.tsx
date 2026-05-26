import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
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
    key_id: number;
    code: string;
    label: string;
    trx_count: number;
    qty: number;
    nilai: number;
}

interface Warehouse {
    id: number;
    code: string;
    name: string;
}

interface Props {
    filters: { from: string; to: string; warehouse_id: number | null; dim: string };
    warehouses: Warehouse[];
    rows: Row[];
    dims: string[];
}

export default function PurchasingIndex({ filters, warehouses, rows, dims }: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.purchasing'), params, { preserveScroll: true });
    }

    const exportHref = (() => {
        const p = new URLSearchParams();
        if (filters.from) p.set('from', filters.from);
        if (filters.to) p.set('to', filters.to);
        if (filters.warehouse_id) p.set('warehouse_id', String(filters.warehouse_id));
        p.set('dim', filters.dim);
        p.set('export', '1');
        return `${route('reports.purchasing')}?${p.toString()}`;
    })();

    const totalNilai = rows.reduce((a, r) => a + r.nilai, 0);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Laporan Pembelian</h2>}
        >
            <Head title="Laporan Pembelian" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
                >
                    <div>
                        <Label htmlFor="dim">Dimensi</Label>
                        <select
                            id="dim"
                            name="dim"
                            defaultValue={filters.dim}
                            className="block h-9 rounded border border-gray-300 bg-white px-2 text-sm"
                        >
                            {dims.map((d) => (
                                <option key={d} value={d}>
                                    {d}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="from">Dari</Label>
                        <Input id="from" name="from" type="date" defaultValue={filters.from} />
                    </div>
                    <div>
                        <Label htmlFor="to">Sampai</Label>
                        <Input id="to" name="to" type="date" defaultValue={filters.to} />
                    </div>
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
                        <a
                            href={exportHref}
                            className="inline-flex h-9 items-center rounded border border-gray-300 bg-white px-4 text-sm font-medium hover:bg-gray-50"
                        >
                            Export Excel
                        </a>
                    </div>
                </form>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>{filters.dim === 'produk' ? 'Produk' : 'Supplier'}</TableHead>
                                    <TableHead className="text-right">Jumlah Penerimaan</TableHead>
                                    <TableHead className="text-right">Total Qty</TableHead>
                                    <TableHead className="text-right">Nilai Pembelian</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center text-sm italic text-gray-500">
                                            Tidak ada penerimaan barang dalam periode ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((r) => (
                                    <TableRow key={`${filters.dim}-${r.key_id}`}>
                                        <TableCell className="font-mono text-xs">{r.code || '-'}</TableCell>
                                        <TableCell>{r.label}</TableCell>
                                        <TableCell className="text-right tabular-nums">{r.trx_count}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatQty(r.qty, 4)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(r.nilai)}</TableCell>
                                    </TableRow>
                                ))}
                                {rows.length > 0 && (
                                    <TableRow className="border-t-2 border-gray-400">
                                        <TableCell colSpan={4} className="text-right font-bold">
                                            TOTAL
                                        </TableCell>
                                        <TableCell className="text-right font-bold tabular-nums">{rupiah(totalNilai)}</TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <p className="text-xs italic text-gray-500">
                    Sumber: goods_receipt_items (event penerimaan). PO yang belum diterima
                    tidak masuk.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
