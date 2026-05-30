import { useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ExportButton from '@/Pages/Reports/_components/ExportButton';
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
import { productTypeLabel } from '@/lib/productTypes';

interface Warehouse {
    id: number;
    code: string;
    name: string;
}
interface UnitInput {
    id: number;
    code: string;
    name: string;
}
interface UserLite {
    id: number;
    name: string;
}
interface Movement {
    id: number;
    type: string;
    qty: string;
    qty_input: string | null;
    cost: string;
    balance_qty_after: string;
    balance_cost_after: string;
    unit_input?: UnitInput | null;
    ref_type: string | null;
    ref_id: number | null;
    notes: string | null;
    user?: UserLite | null;
    created_at: string;
}
interface Product {
    id: number;
    sku: string;
    name: string;
    type: string;
    base_unit_id: number;
}
interface Balance {
    qty: string;
    cost_avg: string;
}
interface Filters {
    warehouse_id: number | null;
    from: string | null;
    to: string | null;
}

interface ColumnOpt {
    key: string;
    label: string;
    default: boolean;
}

interface Props {
    product: Product;
    warehouses: Warehouse[];
    currentWarehouseId: number | null;
    currentWarehouse: Warehouse | null;
    currentBalance: Balance | null;
    movements: Movement[];
    filters: Filters;
    available_columns: ColumnOpt[];
}

// Movements that ADD to inventory show qty in the "in" column; the rest go to "out".
const IN_TYPES = new Set([
    'purchase',
    'transfer_in',
    'adjustment_plus',
    'return_in',
    'opname_plus',
    'compound_in',
]);

const TYPE_LABEL: Record<string, string> = {
    purchase: 'Pembelian',
    sale: 'Penjualan',
    transfer_in: 'Transfer Masuk',
    transfer_out: 'Transfer Keluar',
    adjustment_plus: 'Adj +',
    adjustment_minus: 'Adj −',
    return_in: 'Retur Masuk',
    return_out: 'Retur Keluar',
    opname_plus: 'Opname +',
    opname_minus: 'Opname −',
    compound_in: 'Racik Masuk',
    compound_out: 'Racik Keluar',
    service_consumption: 'Konsumsi Jasa',
};

const TYPE_VARIANT: Record<string, 'success' | 'destructive' | 'info' | 'warning' | 'muted'> = {
    purchase: 'success',
    sale: 'destructive',
    transfer_in: 'success',
    transfer_out: 'warning',
    adjustment_plus: 'info',
    adjustment_minus: 'warning',
    return_in: 'success',
    return_out: 'warning',
    opname_plus: 'info',
    opname_minus: 'warning',
    compound_in: 'success',
    compound_out: 'destructive',
    service_consumption: 'destructive',
};

function shortRef(refType: string | null, refId: number | null): string {
    if (!refType || !refId) return '-';
    const parts = refType.split('\\');
    const tail = parts[parts.length - 1] || refType;
    return `${tail} #${refId}`;
}

export default function StockCard({
    product,
    warehouses,
    currentWarehouseId,
    currentWarehouse,
    currentBalance,
    movements,
    filters,
    available_columns,
}: Props) {
    const [warehouseId, setWarehouseId] = useState<string>(
        currentWarehouseId ? String(currentWarehouseId) : '',
    );
    const [from, setFrom] = useState<string>(filters.from ?? '');
    const [to, setTo] = useState<string>(filters.to ?? '');

    function applyFilters(e?: FormEvent) {
        e?.preventDefault();
        router.get(
            route('inventory.stock_card', product.id),
            {
                warehouse_id: warehouseId || undefined,
                from: from || undefined,
                to: to || undefined,
            },
            { preserveScroll: true, preserveState: true },
        );
    }

    function reset() {
        setFrom('');
        setTo('');
        router.get(
            route('inventory.stock_card', product.id),
            { warehouse_id: warehouseId || undefined },
            { preserveScroll: true },
        );
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold">Kartu Stok</h2>
                    <Link href={route('inventory.stock')} className="text-sm text-sky-700 hover:underline">
                        ← kembali ke daftar stok
                    </Link>
                </div>
            }
        >
            <Head title={`Kartu Stok — ${product.name}`} />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <Card>
                    <CardContent className="space-y-2 p-4">
                        <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <h3 className="text-lg font-medium">{product.name}</h3>
                            <span className="text-sm text-muted-foreground">{product.sku}</span>
                            <Badge variant="muted">{productTypeLabel(product.type)}</Badge>
                        </div>
                        {currentBalance && currentWarehouse && (
                            <div className="grid grid-cols-2 gap-2 text-sm md:grid-cols-3">
                                <div className="rounded-md border p-2">
                                    <div className="text-xs text-muted-foreground">Warehouse</div>
                                    <div className="font-semibold">{currentWarehouse.name}</div>
                                </div>
                                <div className="rounded-md border p-2">
                                    <div className="text-xs text-muted-foreground">Saldo Saat Ini</div>
                                    <div className="font-semibold">{formatQty(currentBalance.qty)}</div>
                                </div>
                                <div className="rounded-md border p-2">
                                    <div className="text-xs text-muted-foreground">HPP Rata-rata</div>
                                    <div className="font-semibold">{rupiah(currentBalance.cost_avg)}</div>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-4">
                        <form onSubmit={applyFilters} className="grid grid-cols-1 gap-3 md:grid-cols-4">
                            <div>
                                <Label htmlFor="sc-wh">Warehouse</Label>
                                <select
                                    id="sc-wh"
                                    value={warehouseId}
                                    onChange={(e) => setWarehouseId(e.target.value)}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                >
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name} ({w.code})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="sc-from">Dari Tanggal</Label>
                                <Input
                                    id="sc-from"
                                    type="date"
                                    value={from}
                                    onChange={(e) => setFrom(e.target.value)}
                                />
                            </div>
                            <div>
                                <Label htmlFor="sc-to">Sampai Tanggal</Label>
                                <Input
                                    id="sc-to"
                                    type="date"
                                    value={to}
                                    onChange={(e) => setTo(e.target.value)}
                                />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button type="submit" className="min-h-11">
                                    Terapkan
                                </Button>
                                <Button type="button" variant="ghost" onClick={reset} className="min-h-11">
                                    Reset
                                </Button>
                                {warehouseId && (
                                    <ExportButton
                                        baseUrl={route('inventory.stock_card.export', product.id)}
                                        params={{
                                            warehouse_id: warehouseId,
                                            from: from || null,
                                            to: to || null,
                                        }}
                                        columns={available_columns}
                                        className="inline-flex min-h-11 items-center rounded-md border border-input bg-background px-4 text-sm font-medium hover:bg-accent"
                                    />
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Tanggal</TableHead>
                                    <TableHead>Tipe</TableHead>
                                    <TableHead className="text-right">Masuk</TableHead>
                                    <TableHead className="text-right">Keluar</TableHead>
                                    <TableHead className="text-right">Saldo</TableHead>
                                    <TableHead className="text-right">Cost</TableHead>
                                    <TableHead>Ref</TableHead>
                                    <TableHead>User</TableHead>
                                    <TableHead>Catatan</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {movements.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={9} className="text-center text-muted-foreground">
                                            Belum ada mutasi pada filter ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {movements.map((m) => {
                                    const isIn = IN_TYPES.has(m.type);
                                    const qty = formatQty(m.qty);
                                    return (
                                        <TableRow key={m.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {formatDateID(m.created_at)}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={TYPE_VARIANT[m.type] ?? 'muted'}>
                                                    {TYPE_LABEL[m.type] ?? m.type}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                {isIn ? qty : ''}
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                {!isIn ? qty : ''}
                                            </TableCell>
                                            <TableCell className="text-right font-mono font-semibold">
                                                {formatQty(m.balance_qty_after)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {rupiah(m.cost)}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {shortRef(m.ref_type, m.ref_id)}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {m.user?.name ?? '-'}
                                            </TableCell>
                                            <TableCell className="text-xs text-muted-foreground">
                                                {m.notes ?? ''}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
