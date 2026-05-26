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
    qty: number;
    omzet: number;
    hpp: number;
    margin: number;
    margin_pct: number;
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
    available_columns: ColumnOption[];
}

export default function Margin({ filters, warehouses, rows, available_columns }: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.sales_margin'), params, { preserveScroll: true });
    }


    const totalOmzet = rows.reduce((a, r) => a + r.omzet, 0);
    const totalHpp = rows.reduce((a, r) => a + r.hpp, 0);
    const totalMargin = totalOmzet - totalHpp;
    const totalPct = totalOmzet > 0 ? (totalMargin / totalOmzet) * 100 : 0;

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Margin Penjualan</h2>}
        >
            <Head title="Margin Penjualan" />
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
                            <option value="produk">Produk</option>
                            <option value="kategori">Kategori</option>
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
                            baseUrl={route('reports.sales_margin')}
                            params={{
                                from: filters.from,
                                to: filters.to,
                                warehouse_id: filters.warehouse_id,
                                dim: filters.dim,
                            }}
                            columns={available_columns}
                        />
                    </div>
                </form>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>{filters.dim === 'kategori' ? 'Kategori' : 'Produk'}</TableHead>
                                    <TableHead className="text-right">Qty</TableHead>
                                    <TableHead className="text-right">Omzet</TableHead>
                                    <TableHead className="text-right">HPP</TableHead>
                                    <TableHead className="text-right">Margin</TableHead>
                                    <TableHead className="text-right">Margin %</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-sm italic text-gray-500">
                                            Tidak ada data dalam periode ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((r) => (
                                    <TableRow key={`${filters.dim}-${r.key_id}`}>
                                        <TableCell className="font-mono text-xs">{r.code || '-'}</TableCell>
                                        <TableCell>{r.label}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatQty(r.qty, 4)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(r.omzet)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(r.hpp)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(r.margin)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{r.margin_pct.toFixed(2)}%</TableCell>
                                    </TableRow>
                                ))}
                                {rows.length > 0 && (
                                    <TableRow className="border-t-2 border-gray-400">
                                        <TableCell colSpan={3} className="text-right font-bold">
                                            TOTAL
                                        </TableCell>
                                        <TableCell className="text-right font-bold tabular-nums">{rupiah(totalOmzet)}</TableCell>
                                        <TableCell className="text-right font-bold tabular-nums">{rupiah(totalHpp)}</TableCell>
                                        <TableCell className="text-right font-bold tabular-nums">{rupiah(totalMargin)}</TableCell>
                                        <TableCell className="text-right font-bold tabular-nums">{totalPct.toFixed(2)}%</TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <p className="text-xs italic text-gray-500">
                    HPP = qty × cost_snapshot (frozen saat sale dibuat). Margin = Omzet − HPP.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
