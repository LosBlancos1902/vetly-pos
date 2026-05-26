import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
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
import { formatDateID, formatQty, rupiah } from '@/lib/utils';
import { type FormEvent } from 'react';

interface MovementRow {
    id: number;
    created_at: string;
    type: string;
    product_id: number;
    sku: string;
    product_name: string;
    warehouse_id: number;
    warehouse_code: string;
    warehouse_name: string;
    qty: string;
    cost: string;
    balance_qty_after: string;
    balance_cost_after: string;
    ref_type: string | null;
    ref_id: number | null;
    reason: string | null;
    notes: string | null;
    user_name: string | null;
}

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    total: number;
    per_page: number;
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
        type: string | null;
        product_id: number | null;
    };
    warehouses: Warehouse[];
    movements: Paginated<MovementRow>;
    movement_types: string[];
}

export default function Movements({ filters, warehouses, movements, movement_types }: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.inventory_movements'), params, { preserveScroll: true });
    }

    const exportHref = (() => {
        const p = new URLSearchParams();
        if (filters.from) p.set('from', filters.from);
        if (filters.to) p.set('to', filters.to);
        if (filters.warehouse_id) p.set('warehouse_id', String(filters.warehouse_id));
        if (filters.type) p.set('type', filters.type);
        if (filters.product_id) p.set('product_id', String(filters.product_id));
        p.set('export', '1');
        return `${route('reports.inventory_movements')}?${p.toString()}`;
    })();

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Mutasi Stok</h2>}
        >
            <Head title="Mutasi Stok" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
                >
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
                                <option value="">— Semua —</option>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.code} — {w.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}
                    <div>
                        <Label htmlFor="type">Tipe</Label>
                        <select
                            id="type"
                            name="type"
                            defaultValue={filters.type ?? ''}
                            className="block h-9 rounded border border-gray-300 bg-white px-2 text-sm"
                        >
                            <option value="">— Semua —</option>
                            {movement_types.map((t) => (
                                <option key={t} value={t}>
                                    {t}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="product_id">Product ID</Label>
                        <Input
                            id="product_id"
                            name="product_id"
                            type="number"
                            defaultValue={filters.product_id ?? ''}
                            placeholder="opsional"
                        />
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

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Waktu</TableHead>
                                    <TableHead>Tipe</TableHead>
                                    <TableHead>Produk</TableHead>
                                    <TableHead>Cabang</TableHead>
                                    <TableHead className="text-right">Qty</TableHead>
                                    <TableHead className="text-right">Cost</TableHead>
                                    <TableHead className="text-right">Saldo Qty</TableHead>
                                    <TableHead>Ref</TableHead>
                                    <TableHead>User</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {movements.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={9} className="text-center text-sm italic text-gray-500">
                                            Tidak ada mutasi dalam periode.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {movements.data.map((m) => (
                                    <TableRow key={m.id}>
                                        <TableCell className="text-xs">{formatDateID(m.created_at)}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline">{m.type}</Badge>
                                        </TableCell>
                                        <TableCell>
                                            <div>{m.product_name}</div>
                                            <div className="font-mono text-xs text-gray-500">{m.sku}</div>
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            <span className="font-mono">{m.warehouse_code}</span>{' '}
                                            {m.warehouse_name}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{formatQty(m.qty, 4)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(m.cost)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{formatQty(m.balance_qty_after, 4)}</TableCell>
                                        <TableCell className="text-xs text-gray-500">
                                            {m.ref_type ?? '-'}
                                            {m.ref_id ? ` #${m.ref_id}` : ''}
                                        </TableCell>
                                        <TableCell className="text-xs">{m.user_name ?? '-'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="text-xs text-gray-500">
                        Total: {movements.total} mutasi
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {movements.links.map((l, i) => (
                            <Link
                                key={i}
                                href={l.url ?? '#'}
                                preserveScroll
                                className={
                                    'rounded border px-3 py-1 text-sm ' +
                                    (l.active
                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                        : l.url
                                          ? 'border-gray-300 bg-white hover:bg-gray-50'
                                          : 'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400')
                                }
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
