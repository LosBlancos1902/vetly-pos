import { useMemo, useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
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
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { rupiah } from '@/lib/utils';

type Status = 'draft' | 'counting' | 'completed' | 'cancelled';

interface UnitLite {
    id: number;
    code: string;
    name: string;
}
interface ProductLite {
    id: number;
    sku: string;
    name: string;
    base_unit_id: number;
    base_unit?: UnitLite;
}
interface OpnameItem {
    id: number;
    product_id: number;
    qty_system: string;
    qty_physical: string | null;
    qty_diff: string | null;
    notes: string | null;
    product: ProductLite;
}
interface Opname {
    id: number;
    opname_no: string;
    status: Status;
    opname_date: string;
    catatan: string | null;
    completed_at: string | null;
    cancelled_reason: string | null;
    warehouse: { id: number; code: string; name: string };
    creator: { id: number; name: string };
    completer: { id: number; name: string } | null;
    items: OpnameItem[];
}

interface PendingSummary {
    count: number;
    total_cogs: number;
}

interface Props {
    opname: Opname;
    pendingSummary: PendingSummary;
}

const STATUS_LABEL: Record<Status, { label: string; variant: 'default' | 'info' | 'success' | 'destructive' | 'muted' }> = {
    draft: { label: 'Draft', variant: 'muted' },
    counting: { label: 'Counting', variant: 'info' },
    completed: { label: 'Selesai', variant: 'success' },
    cancelled: { label: 'Batal', variant: 'destructive' },
};

export default function StockOpnameCounting({ opname, pendingSummary }: Props) {
    const readOnly = opname.status === 'completed' || opname.status === 'cancelled';
    const [rows, setRows] = useState(() =>
        opname.items.map((it) => ({
            id: it.id,
            qty_system: it.qty_system,
            qty_physical: it.qty_physical ?? '',
            notes: it.notes ?? '',
            product: it.product,
        })),
    );
    const [search, setSearch] = useState('');
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelReason, setCancelReason] = useState('');
    const [completeOpen, setCompleteOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [uploading, setUploading] = useState(false);

    const filtered = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return rows;
        return rows.filter(
            (r) =>
                r.product.name.toLowerCase().includes(term) ||
                r.product.sku.toLowerCase().includes(term),
        );
    }, [rows, search]);

    function updateRow(id: number, patch: Partial<typeof rows[number]>) {
        setRows((prev) => prev.map((r) => (r.id === id ? { ...r, ...patch } : r)));
    }

    function diffFor(r: typeof rows[number]): number | null {
        if (r.qty_physical === '' || r.qty_physical === null) return null;
        return Number(r.qty_physical) - Number(r.qty_system);
    }

    function diffSummary() {
        let plus = 0, minus = 0, zero = 0, empty = 0;
        rows.forEach((r) => {
            const d = diffFor(r);
            if (d === null) empty++;
            else if (d > 0) plus++;
            else if (d < 0) minus++;
            else zero++;
        });
        return { plus, minus, zero, empty };
    }

    function saveProgress() {
        setSubmitting(true);
        router.put(
            route('inventory.opnames.update_items', opname.id),
            {
                items: rows.map((r) => ({
                    id: r.id,
                    qty_physical: r.qty_physical === '' ? null : Number(r.qty_physical),
                    notes: r.notes || null,
                })),
            },
            {
                onSuccess: () => toast.success('Hasil hitung disimpan'),
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal simpan'),
                onFinish: () => setSubmitting(false),
                preserveScroll: true,
            },
        );
    }

    function openCompleteConfirm() {
        setCompleteOpen(true);
    }

    function confirmComplete() {
        // Save progress dulu, lalu complete dalam satu rangkaian.
        setSubmitting(true);
        router.put(
            route('inventory.opnames.update_items', opname.id),
            {
                items: rows.map((r) => ({
                    id: r.id,
                    qty_physical: r.qty_physical === '' ? null : Number(r.qty_physical),
                    notes: r.notes || null,
                })),
            },
            {
                onSuccess: () => {
                    router.post(
                        route('inventory.opnames.complete', opname.id),
                        {},
                        {
                            onSuccess: () => {
                                toast.success('Opname selesai');
                                setCompleteOpen(false);
                            },
                            onError: (errs) =>
                                toast.error((Object.values(errs)[0] as string) ?? 'Gagal complete'),
                            onFinish: () => setSubmitting(false),
                        },
                    );
                },
                onError: (errs) => {
                    toast.error((Object.values(errs)[0] as string) ?? 'Gagal simpan');
                    setSubmitting(false);
                },
            },
        );
    }

    function uploadExcel(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;

        setUploading(true);
        const form = new FormData();
        form.append('file', file);

        router.post(route('inventory.opnames.excel.upload', opname.id), form, {
            forceFormData: true,
            onSuccess: () => toast.success('Excel diimport, refresh untuk lihat hasil.'),
            onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal upload'),
            onFinish: () => {
                setUploading(false);
                e.target.value = ''; // reset agar file sama bisa upload ulang
            },
        });
    }

    function doCancel(e: FormEvent) {
        e.preventDefault();
        router.post(
            route('inventory.opnames.cancel', opname.id),
            { cancelled_reason: cancelReason },
            {
                onSuccess: () => {
                    toast.success('Opname dibatalkan');
                    setCancelOpen(false);
                },
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal'),
            },
        );
    }

    const meta = STATUS_LABEL[opname.status];
    const summary = diffSummary();

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold">Opname {opname.opname_no}</h2>
                    <Badge variant={meta.variant}>{meta.label}</Badge>
                </div>
            }
        >
            <Head title={`Opname ${opname.opname_no}`} />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <Card>
                    <CardContent className="space-y-2 p-4 text-sm">
                        <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                            <div>
                                <span className="text-muted-foreground">Gudang:</span>{' '}
                                {opname.warehouse.name}
                            </div>
                            <div>
                                <span className="text-muted-foreground">Tanggal:</span>{' '}
                                {opname.opname_date}
                            </div>
                            <div>
                                <span className="text-muted-foreground">Dibuat:</span>{' '}
                                {opname.creator.name}
                            </div>
                            {opname.completer && (
                                <div>
                                    <span className="text-muted-foreground">Diselesaikan:</span>{' '}
                                    {opname.completer.name}
                                </div>
                            )}
                        </div>
                        {opname.catatan && (
                            <div>
                                <span className="text-muted-foreground">Catatan:</span> {opname.catatan}
                            </div>
                        )}
                        {opname.cancelled_reason && (
                            <div className="rounded-md bg-destructive/10 p-2 text-destructive">
                                <span className="font-medium">Alasan batal:</span> {opname.cancelled_reason}
                            </div>
                        )}

                        <div className="flex flex-wrap gap-3 border-t pt-2 text-xs">
                            <span className="rounded-md bg-green-100 px-2 py-1">+ {summary.plus}</span>
                            <span className="rounded-md bg-red-100 px-2 py-1">- {summary.minus}</span>
                            <span className="rounded-md bg-gray-100 px-2 py-1">= {summary.zero}</span>
                            <span className="rounded-md bg-gray-100 px-2 py-1">
                                belum {summary.empty}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <div className="flex items-center justify-between">
                    <Input
                        placeholder="Cari nama / SKU"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-72"
                    />
                    {!readOnly && (
                        <div className="flex flex-wrap gap-2">
                            <a
                                href={route('inventory.opnames.excel.download', opname.id)}
                                className="inline-flex h-10 items-center justify-center rounded-md border border-input bg-background px-4 text-sm font-medium hover:bg-accent"
                            >
                                Download Excel
                            </a>
                            <label className="inline-flex h-10 cursor-pointer items-center justify-center rounded-md border border-input bg-background px-4 text-sm font-medium hover:bg-accent">
                                {uploading ? 'Upload…' : 'Upload Excel'}
                                <input
                                    type="file"
                                    accept=".xlsx,.xls"
                                    className="hidden"
                                    onChange={uploadExcel}
                                    disabled={uploading || submitting}
                                />
                            </label>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={saveProgress}
                                disabled={submitting}
                            >
                                Simpan Progres
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => setCancelOpen(true)}
                            >
                                Batalkan
                            </Button>
                            <Button type="button" onClick={openCompleteConfirm} disabled={submitting}>
                                Selesaikan
                            </Button>
                        </div>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Produk</TableHead>
                                    <TableHead className="text-right">Qty Sistem</TableHead>
                                    <TableHead className="text-right">Qty Fisik</TableHead>
                                    <TableHead className="text-right">Selisih</TableHead>
                                    <TableHead>Catatan</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center text-muted-foreground">
                                            Tidak ada produk yang cocok.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {filtered.map((r) => {
                                    const d = diffFor(r);
                                    return (
                                        <TableRow key={r.id}>
                                            <TableCell>
                                                <div>{r.product.name}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {r.product.sku}
                                                    {r.product.base_unit && ` · ${r.product.base_unit.code}`}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {Number(r.qty_system)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Input
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    value={r.qty_physical}
                                                    onChange={(e) =>
                                                        updateRow(r.id, { qty_physical: e.target.value })
                                                    }
                                                    className="w-28 text-right"
                                                    disabled={readOnly}
                                                />
                                            </TableCell>
                                            <TableCell className={`text-right ${
                                                d === null
                                                    ? 'text-muted-foreground'
                                                    : d > 0
                                                      ? 'text-green-700'
                                                      : d < 0
                                                        ? 'text-red-700'
                                                        : ''
                                            }`}>
                                                {d === null ? '-' : d > 0 ? `+${d}` : d}
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    value={r.notes}
                                                    onChange={(e) => updateRow(r.id, { notes: e.target.value })}
                                                    placeholder="rusak / hilang / dst"
                                                    disabled={readOnly}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={completeOpen} onOpenChange={(o) => !submitting && setCompleteOpen(o)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Selesaikan Opname {opname.opname_no}?</DialogTitle>
                    </DialogHeader>
                    {(() => {
                        const diffItems = rows.filter((r) => {
                            const d = diffFor(r);
                            return d !== null && d !== 0;
                        });
                        const totalAbsDiff = diffItems.reduce(
                            (sum, r) => sum + Math.abs(diffFor(r) ?? 0),
                            0,
                        );
                        const hasDiff = diffItems.length > 0;
                        const hasPending = pendingSummary.count > 0;
                        const isSimple = !hasDiff && !hasPending;

                        return (
                            <div className="space-y-4 text-sm">
                                {isSimple ? (
                                    <p>
                                        Tidak ada selisih dan tidak ada penjualan tertahan.
                                        Lanjutkan menyelesaikan opname?
                                    </p>
                                ) : (
                                    <>
                                        <div className="rounded-md border bg-muted/50 p-3 space-y-2">
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">Item dengan selisih</span>
                                                <span className="font-semibold">
                                                    {hasDiff
                                                        ? `${diffItems.length} item · total ${totalAbsDiff.toFixed(2)} qty`
                                                        : 'tidak ada'}
                                                </span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span className="text-muted-foreground">Penjualan tertahan</span>
                                                <span className="font-semibold">
                                                    {hasPending
                                                        ? `${pendingSummary.count} transaksi · HPP ${rupiah(pendingSummary.total_cogs)}`
                                                        : 'tidak ada'}
                                                </span>
                                            </div>
                                        </div>

                                        <div className="text-muted-foreground">
                                            <p className="mb-1 font-medium text-foreground">
                                                Menyelesaikan opname ini akan:
                                            </p>
                                            <ol className="list-decimal space-y-1 pl-5">
                                                {hasDiff && (
                                                    <li>
                                                        Menyesuaikan stok ke hasil fisik
                                                        {' '}({diffItems.length} item) + post jurnal selisih
                                                    </li>
                                                )}
                                                {hasPending && (
                                                    <li>
                                                        Memproses {pendingSummary.count} penjualan tertahan
                                                        {' '}→ potong stok + post jurnal HPP{' '}
                                                        ({rupiah(pendingSummary.total_cogs)})
                                                    </li>
                                                )}
                                            </ol>
                                        </div>

                                        <p className="text-xs text-muted-foreground">
                                            Aksi ini tidak bisa dibatalkan setelah dikonfirmasi.
                                        </p>
                                    </>
                                )}
                            </div>
                        );
                    })()}
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setCompleteOpen(false)}
                            disabled={submitting}
                        >
                            Batal
                        </Button>
                        <Button type="button" onClick={confirmComplete} disabled={submitting}>
                            {submitting ? 'Memproses…' : 'Konfirmasi'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={cancelOpen} onOpenChange={setCancelOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Batalkan Opname {opname.opname_no}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={doCancel} className="space-y-3">
                        <div>
                            <Label htmlFor="cancel-reason">Alasan</Label>
                            <Input
                                id="cancel-reason"
                                value={cancelReason}
                                onChange={(e) => setCancelReason(e.target.value)}
                                required
                                minLength={3}
                                autoFocus
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCancelOpen(false)}>
                                Tutup
                            </Button>
                            <Button type="submit" variant="destructive">
                                Batalkan Opname
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
