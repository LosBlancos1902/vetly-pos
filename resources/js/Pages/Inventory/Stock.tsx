import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { formatQty, rupiah } from '@/lib/utils';

interface Row {
    id: number;
    qty: string;
    cost_avg: string;
    product?: { id: number; sku: string; name: string; type: string; min_stock: string };
    warehouse?: { id: number; code: string; name: string };
}
interface Warehouse {
    id: number;
    code: string;
    name: string;
}
interface Filters {
    warehouse_id: number | null;
    type: string | null;
    search: string | null;
}

interface Summary {
    sku_count: number;
    total_value: number;
    low_stock_count: number;
}

interface SummaryWarehouse {
    id: number;
    code: string;
    name: string;
    warehouse_type: string;
}

interface Props {
    inventories: { data: Row[]; links?: Array<{ url: string | null; label: string; active: boolean }> };
    warehouses: Warehouse[];
    productTypes: Record<string, string>;
    summary: Summary | null;
    summaryWarehouse: SummaryWarehouse | null;
    filters: Filters;
}

const TYPE_VARIANT: Record<string, 'info' | 'warning' | 'muted' | 'success' | 'secondary'> = {
    saleable_retail: 'success',
    compoundable_drug: 'info',
    raw_material: 'warning',
    service: 'muted',
    service_with_consumption: 'muted',
};

export default function Stock({
    inventories,
    warehouses,
    productTypes,
    summary,
    summaryWarehouse,
    filters,
}: Props) {
    const [warehouseId, setWarehouseId] = useState<string>(
        filters.warehouse_id ? String(filters.warehouse_id) : '',
    );
    const [type, setType] = useState<string>(filters.type ?? '');
    const [search, setSearch] = useState<string>(filters.search ?? '');

    function applyFilters(e?: FormEvent) {
        e?.preventDefault();
        router.get(
            route('inventory.stock'),
            {
                warehouse_id: warehouseId || undefined,
                type: type || undefined,
                search: search || undefined,
            },
            { preserveScroll: true, preserveState: true },
        );
    }

    function reset() {
        setWarehouseId('');
        setType('');
        setSearch('');
        router.get(route('inventory.stock'), {}, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Stok</h2>}>
            <Head title="Stok" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                {summary && summaryWarehouse && (
                    <Card>
                        <CardContent className="p-4">
                            <div className="mb-3 flex items-baseline justify-between gap-2">
                                <div>
                                    <div className="text-xs uppercase tracking-wide text-muted-foreground">
                                        Ringkasan Isi Gudang
                                    </div>
                                    <div className="text-lg font-semibold">
                                        {summaryWarehouse.name}{' '}
                                        <span className="text-sm font-normal text-muted-foreground">
                                            ({summaryWarehouse.code})
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground">SKU Aktif (qty {'>'} 0)</div>
                                    <div className="text-2xl font-bold">
                                        {formatQty(summary.sku_count, 0)}
                                    </div>
                                </div>
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground">Total Nilai Stok (HPP)</div>
                                    <div className="text-2xl font-bold">
                                        {rupiah(summary.total_value)}
                                    </div>
                                </div>
                                <div className="rounded-md border p-3">
                                    <div className="text-xs text-muted-foreground">Produk Stok Menipis</div>
                                    <div className="text-2xl font-bold">
                                        {summary.low_stock_count > 0 ? (
                                            <span className="text-amber-700">
                                                {formatQty(summary.low_stock_count, 0)}
                                            </span>
                                        ) : (
                                            <span className="text-emerald-700">0</span>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardContent className="p-4">
                        <form onSubmit={applyFilters} className="grid grid-cols-1 gap-3 md:grid-cols-4">
                            <div>
                                <Label htmlFor="f-wh">Warehouse</Label>
                                <select
                                    id="f-wh"
                                    value={warehouseId}
                                    onChange={(e) => setWarehouseId(e.target.value)}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                >
                                    <option value="">— Semua —</option>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name} ({w.code})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-type">Tipe Produk</Label>
                                <select
                                    id="f-type"
                                    value={type}
                                    onChange={(e) => setType(e.target.value)}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                >
                                    <option value="">— Semua —</option>
                                    {Object.entries(productTypes).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-search">Cari (SKU / nama)</Label>
                                <Input
                                    id="f-search"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="cth: SKU-001 atau Royal Canin"
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button type="submit" className="min-h-11">
                                    Terapkan
                                </Button>
                                <Button type="button" variant="ghost" onClick={reset} className="min-h-11">
                                    Reset
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Produk</TableHead>
                                    <TableHead>Tipe</TableHead>
                                    <TableHead>Gudang</TableHead>
                                    <TableHead className="text-right">Qty</TableHead>
                                    <TableHead className="text-right">HPP Rata2</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {inventories.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                            Tidak ada data dengan filter ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {inventories.data.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>
                                            <div className="font-medium">{r.product?.name}</div>
                                            <div className="text-xs text-muted-foreground">{r.product?.sku}</div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={TYPE_VARIANT[r.product?.type ?? ''] ?? 'muted'}>
                                                {productTypes[r.product?.type ?? ''] ?? r.product?.type ?? '-'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>{r.warehouse?.name}</TableCell>
                                        <TableCell className="text-right font-mono">{formatQty(r.qty)}</TableCell>
                                        <TableCell className="text-right">{rupiah(r.cost_avg)}</TableCell>
                                        <TableCell className="text-right">
                                            {r.product && (
                                                <Link
                                                    href={
                                                        route('inventory.stock_card', r.product.id) +
                                                        (r.warehouse?.id ? `?warehouse_id=${r.warehouse.id}` : '')
                                                    }
                                                    className="text-sm text-sky-700 hover:underline"
                                                >
                                                    Kartu Stok →
                                                </Link>
                                            )}
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
