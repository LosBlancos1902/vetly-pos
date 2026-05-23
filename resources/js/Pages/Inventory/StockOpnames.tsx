import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Card, CardContent } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';

type Status = 'draft' | 'counting' | 'completed' | 'cancelled';

interface WarehouseLite {
    id: number;
    code: string;
    name: string;
}
interface UserLite {
    id: number;
    name: string;
}
interface OpnameItem {
    id: number;
    qty_diff: string | null;
}
interface Opname {
    id: number;
    opname_no: string;
    status: Status;
    opname_date: string;
    catatan: string | null;
    created_at: string;
    completed_at: string | null;
    warehouse?: WarehouseLite;
    creator?: UserLite;
    completer?: UserLite | null;
    items: OpnameItem[];
}

interface Paginated {
    data: Opname[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    opnames: Paginated;
    filters: { status?: Status; warehouse_id?: number };
    warehouses: WarehouseLite[];
}

const STATUS_LABEL: Record<Status, { label: string; variant: 'default' | 'info' | 'success' | 'destructive' | 'muted' }> = {
    draft: { label: 'Draft', variant: 'muted' },
    counting: { label: 'Counting', variant: 'info' },
    completed: { label: 'Selesai', variant: 'success' },
    cancelled: { label: 'Batal', variant: 'destructive' },
};

export default function StockOpnames({ opnames, filters }: Props) {
    function filterStatus(s: Status | '') {
        router.get(
            route('inventory.opnames.index'),
            s ? { ...filters, status: s } : { ...filters, status: undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Stock Opname</h2>}>
            <Head title="Stock Opname" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <Label htmlFor="status-filter">Status:</Label>
                        <select
                            id="status-filter"
                            value={filters.status ?? ''}
                            onChange={(e) => filterStatus(e.target.value as Status | '')}
                            className="flex h-10 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Semua</option>
                            <option value="draft">Draft</option>
                            <option value="counting">Counting</option>
                            <option value="completed">Selesai</option>
                            <option value="cancelled">Batal</option>
                        </select>
                    </div>
                    <Link href={route('inventory.opnames.create')}>
                        <Button className="min-h-11">+ Buat Opname</Button>
                    </Link>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Opname No</TableHead>
                                    <TableHead>Gudang</TableHead>
                                    <TableHead>Tanggal</TableHead>
                                    <TableHead className="text-right">Item</TableHead>
                                    <TableHead className="text-right">Selisih</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {opnames.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground">
                                            Belum ada opname.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {opnames.data.map((o) => {
                                    const meta = STATUS_LABEL[o.status];
                                    const totalDiff = o.items.reduce(
                                        (sum, it) => sum + Math.abs(Number(it.qty_diff ?? 0)),
                                        0,
                                    );
                                    return (
                                        <TableRow key={o.id}>
                                            <TableCell className="font-mono text-xs">{o.opname_no}</TableCell>
                                            <TableCell>{o.warehouse?.name ?? '-'}</TableCell>
                                            <TableCell>{o.opname_date}</TableCell>
                                            <TableCell className="text-right">{o.items.length}</TableCell>
                                            <TableCell className="text-right">
                                                {totalDiff > 0 ? totalDiff.toFixed(2) : '-'}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={meta.variant}>{meta.label}</Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Link href={route('inventory.opnames.show', o.id)}>
                                                    <Button variant="ghost" size="sm">
                                                        {o.status === 'completed' || o.status === 'cancelled'
                                                            ? 'Lihat'
                                                            : 'Lanjutkan'}
                                                    </Button>
                                                </Link>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {opnames.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>
                            {opnames.from}–{opnames.to} dari {opnames.total}
                        </div>
                        <div className="flex gap-1">
                            {opnames.links.map((l, i) => (
                                <Button
                                    key={i}
                                    variant={l.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!l.url}
                                    onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true })}
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
