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
import ExportButton from '../_components/ExportButton';
import { ColumnOption } from '../_components/ExportColumnPickerModal';

interface Row {
    key_id: number;
    code: string;
    label: string;
    trx_count: number;
    qty: number;
    omzet: number;
}

interface Warehouse {
    id: number;
    code: string;
    name: string;
}

interface Props {
    filters: {
        from: string;
        to: string;
        warehouse_id: number | null;
        dim: string;
    };
    warehouses: Warehouse[];
    rows: Row[];
    dims: string[];
    available_columns: ColumnOption[];
}

export default function MultiDim({ filters, warehouses, rows, dims, available_columns }: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.sales'), params, { preserveScroll: true });
    }


    const totalOmzet = rows.reduce((a, r) => a + r.omzet, 0);
    const totalQty = rows.reduce((a, r) => a + r.qty, 0);
    const totalTrx = rows.reduce((a, r) => a + r.trx_count, 0);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Laporan Penjualan</h2>}
        >
            <Head title="Laporan Penjualan" />
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
                        <ExportButton
                            baseUrl={route('reports.sales')}
                            params={{
                                from: filters.from,
                                to: filters.to,
                                warehouse_id: filters.warehouse_id,
                                dim: filters.dim,
                            }}
                            columns={available_columns}
                            label="Export Detail (Excel)"
                        />
                    </div>
                </form>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>{labelFor(filters.dim)}</TableHead>
                                    <TableHead className="text-right">Trx</TableHead>
                                    <TableHead className="text-right">Total Qty</TableHead>
                                    <TableHead className="text-right">Omzet</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center text-sm italic text-gray-500">
                                            Tidak ada penjualan completed dalam periode ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((r) => (
                                    <TableRow key={`${filters.dim}-${r.key_id}`}>
                                        <TableCell className="font-mono text-xs">{r.code || '-'}</TableCell>
                                        <TableCell>{r.label}</TableCell>
                                        <TableCell className="text-right tabular-nums">{r.trx_count}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatQty(r.qty, 4)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {rupiah(r.omzet)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {rows.length > 0 && (
                                    <TableRow className="border-t-2 border-gray-400">
                                        <TableCell colSpan={2} className="text-right font-bold">
                                            TOTAL
                                        </TableCell>
                                        <TableCell className="text-right font-bold tabular-nums">
                                            {totalTrx}
                                        </TableCell>
                                        <TableCell className="text-right font-bold tabular-nums">
                                            {formatQty(totalQty, 4)}
                                        </TableCell>
                                        <TableCell className="text-right font-bold tabular-nums">
                                            {rupiah(totalOmzet)}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <p className="text-xs italic text-gray-500">
                    Sumber: sales completed (void dikecualikan). Konsisten dengan total
                    Pendapatan di Laba Rugi periode sama.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}

function labelFor(dim: string): string {
    return (
        {
            produk: 'Produk',
            kategori: 'Kategori',
            pelanggan: 'Pelanggan',
            kasir: 'Kasir',
            cabang: 'Cabang',
        }[dim] ?? 'Item'
    );
}
