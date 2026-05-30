import { useEffect, useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
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

interface Warehouse { id: number; code: string; name: string }
interface ProductLite { id: number; sku: string; name: string; type: string; cost_avg: string }

interface MovementRow {
    id: number;
    type: 'adjustment_plus' | 'adjustment_minus';
    qty: string;
    cost: string;
    reason: string | null;
    notes: string | null;
    created_at: string;
    product?: { id: number; sku: string; name: string };
    warehouse?: { id: number; code: string; name: string };
    user?: { id: number; name: string };
}

interface Paginated {
    data: MovementRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Filters {
    warehouse_id: number | null;
    reason: string | null;
    from: string | null;
    to: string | null;
}

interface PreviewResp {
    cost_avg: number;
    current_qty: number;
    amount: number;
    is_plus: boolean;
}

interface Props {
    movements: Paginated;
    warehouses: Warehouse[];
    reasonLabels: Record<string, string>;
    filters: Filters;
}

const REASON_VARIANT: Record<string, 'destructive' | 'warning' | 'info' | 'muted'> = {
    rusak: 'destructive',
    hilang: 'destructive',
    expired: 'warning',
    koreksi: 'info',
};

export default function Adjustments({ movements, warehouses, reasonLabels, filters }: Props) {
    // ── Filter form ────────────────────────────────────────────────
    const [filtWh, setFiltWh] = useState(filters.warehouse_id ? String(filters.warehouse_id) : '');
    const [filtReason, setFiltReason] = useState(filters.reason ?? '');
    const [filtFrom, setFiltFrom] = useState(filters.from ?? '');
    const [filtTo, setFiltTo] = useState(filters.to ?? '');

    function applyFilters(e?: FormEvent) {
        e?.preventDefault();
        router.get(route('inventory.adjustments.index'), {
            warehouse_id: filtWh || undefined,
            reason: filtReason || undefined,
            from: filtFrom || undefined,
            to: filtTo || undefined,
        }, { preserveScroll: true, preserveState: true });
    }

    function resetFilters() {
        setFiltWh(''); setFiltReason(''); setFiltFrom(''); setFiltTo('');
        router.get(route('inventory.adjustments.index'), {}, { preserveScroll: true });
    }

    // ── Create modal ───────────────────────────────────────────────
    const [open, setOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [warehouseId, setWarehouseId] = useState('');
    const [product, setProduct] = useState<ProductLite | null>(null);
    const [searchQ, setSearchQ] = useState('');
    const [searchResults, setSearchResults] = useState<ProductLite[]>([]);
    const [searching, setSearching] = useState(false);
    const [qty, setQty] = useState(''); // signed: positive=plus, negative=minus
    const [reason, setReason] = useState<'rusak' | 'hilang' | 'expired' | 'koreksi'>('rusak');
    const [notes, setNotes] = useState('');
    const [preview, setPreview] = useState<PreviewResp | null>(null);

    function resetForm() {
        setWarehouseId(''); setProduct(null); setSearchQ('');
        setSearchResults([]); setQty(''); setReason('rusak');
        setNotes(''); setPreview(null);
    }

    function startCreate() {
        resetForm();
        if (warehouses.length === 1) setWarehouseId(String(warehouses[0].id));
        setOpen(true);
    }

    // Debounced product search.
    useEffect(() => {
        if (searchQ.trim().length < 2) {
            setSearchResults([]);
            return;
        }
        setSearching(true);
        const ctrl = new AbortController();
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.get<{ results: ProductLite[] }>(
                    route('inventory.adjustments.products.search'),
                    { params: { q: searchQ }, signal: ctrl.signal },
                );
                setSearchResults(data.results);
            } catch (e) {
                if (! axios.isCancel(e)) setSearchResults([]);
            } finally {
                setSearching(false);
            }
        }, 250);
        return () => { clearTimeout(t); ctrl.abort(); };
    }, [searchQ]);

    // Debounced preview saat product + warehouse + qty terisi.
    useEffect(() => {
        if (! product || ! warehouseId || ! qty || parseFloat(qty) === 0) {
            setPreview(null);
            return;
        }
        const ctrl = new AbortController();
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.post<PreviewResp>(
                    route('inventory.adjustments.preview'),
                    { product_id: product.id, warehouse_id: Number(warehouseId), qty: parseFloat(qty) },
                    { signal: ctrl.signal },
                );
                setPreview(data);
            } catch (e) {
                if (! axios.isCancel(e)) setPreview(null);
            }
        }, 200);
        return () => { clearTimeout(t); ctrl.abort(); };
    }, [product, warehouseId, qty]);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (! product || ! warehouseId || ! qty) return;
        setSubmitting(true);
        router.post(route('inventory.adjustments.store'), {
            product_id: product.id,
            warehouse_id: Number(warehouseId),
            qty: parseFloat(qty),
            reason,
            notes: notes.trim() || null,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Penyesuaian stok tersimpan');
                setOpen(false);
                resetForm();
            },
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
            onFinish: () => setSubmitting(false),
        });
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Penyesuaian Stok</h2>}
        >
            <Head title="Penyesuaian Stok" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm text-muted-foreground">
                        Adjustment manual — koreksi rusak / hilang / kadaluwarsa.
                        Jurnal otomatis ke-post (D 5100 / C 1201 atau sebaliknya).
                    </h3>
                    <Button type="button" onClick={startCreate}>+ Tambah Penyesuaian</Button>
                </div>

                <Card>
                    <CardContent className="p-4">
                        <form onSubmit={applyFilters} className="grid grid-cols-1 gap-3 md:grid-cols-5">
                            <div>
                                <Label htmlFor="f-wh">Gudang</Label>
                                <select id="f-wh" value={filtWh}
                                    onChange={(e) => setFiltWh(e.target.value)}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                    <option value="">— Semua —</option>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>{w.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-reason">Kategori</Label>
                                <select id="f-reason" value={filtReason}
                                    onChange={(e) => setFiltReason(e.target.value)}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                    <option value="">— Semua —</option>
                                    {Object.entries(reasonLabels).map(([k, v]) => (
                                        <option key={k} value={k}>{v}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-from">Dari</Label>
                                <Input id="f-from" type="date" value={filtFrom}
                                    onChange={(e) => setFiltFrom(e.target.value)} />
                            </div>
                            <div>
                                <Label htmlFor="f-to">Sampai</Label>
                                <Input id="f-to" type="date" value={filtTo}
                                    onChange={(e) => setFiltTo(e.target.value)} />
                            </div>
                            <div className="flex items-end gap-2">
                                <Button type="submit" className="min-h-11">Terapkan</Button>
                                <Button type="button" variant="ghost" onClick={resetFilters} className="min-h-11">
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
                                    <TableHead>Tanggal</TableHead>
                                    <TableHead>Produk</TableHead>
                                    <TableHead>Gudang</TableHead>
                                    <TableHead>Kategori</TableHead>
                                    <TableHead className="text-right">Qty</TableHead>
                                    <TableHead className="text-right">Nilai</TableHead>
                                    <TableHead>User</TableHead>
                                    <TableHead>Catatan</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {movements.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-center text-muted-foreground">
                                            Belum ada penyesuaian dengan filter ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {movements.data.map((m) => {
                                    const isPlus = m.type === 'adjustment_plus';
                                    const amount = parseFloat(m.qty) * parseFloat(m.cost);
                                    return (
                                        <TableRow key={m.id}>
                                            <TableCell className="whitespace-nowrap text-sm">
                                                {formatDateID(m.created_at)}
                                            </TableCell>
                                            <TableCell>
                                                <div className="font-medium">{m.product?.name}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {m.product?.sku}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-sm">{m.warehouse?.name}</TableCell>
                                            <TableCell>
                                                {m.reason ? (
                                                    <Badge variant={REASON_VARIANT[m.reason] ?? 'muted'}>
                                                        {reasonLabels[m.reason] ?? m.reason}
                                                    </Badge>
                                                ) : '—'}
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                <span className={isPlus ? 'text-emerald-700' : 'text-red-700'}>
                                                    {isPlus ? '+' : '−'}{formatQty(m.qty)}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {rupiah(amount)}
                                            </TableCell>
                                            <TableCell className="text-sm">{m.user?.name ?? '—'}</TableCell>
                                            <TableCell className="max-w-xs truncate text-xs text-muted-foreground">
                                                {m.notes ?? ''}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {movements.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>{movements.from}–{movements.to} dari {movements.total}</div>
                        <div className="flex gap-1">
                            {movements.links.map((l, i) => (
                                <Button key={i}
                                    variant={l.active ? 'default' : 'outline'}
                                    size="sm" disabled={! l.url}
                                    onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true })}
                                    dangerouslySetInnerHTML={{ __html: l.label }} />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* ── Modal Create ─────────────────────────────────────── */}
            <Dialog open={open} onOpenChange={(o) => ! submitting && setOpen(o)}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Tambah Penyesuaian Stok</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div>
                            <Label htmlFor="a-wh">Gudang *</Label>
                            <select id="a-wh" value={warehouseId}
                                onChange={(e) => setWarehouseId(e.target.value)}
                                required
                                className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                <option value="">— Pilih gudang —</option>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>{w.name} ({w.code})</option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <Label htmlFor="a-search">Produk *</Label>
                            {product ? (
                                <div className="flex items-center justify-between rounded-md border bg-muted/30 px-3 py-2">
                                    <div>
                                        <div className="font-medium">{product.name}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {product.sku} · {productTypeLabel(product.type)}
                                        </div>
                                    </div>
                                    <Button type="button" size="sm" variant="ghost"
                                        onClick={() => { setProduct(null); setSearchQ(''); }}>
                                        Ganti
                                    </Button>
                                </div>
                            ) : (
                                <>
                                    <Input id="a-search" value={searchQ}
                                        onChange={(e) => setSearchQ(e.target.value)}
                                        placeholder="Ketik SKU/nama/barcode (min 2 huruf)…" />
                                    {searching && (
                                        <p className="mt-1 text-xs text-muted-foreground">Mencari…</p>
                                    )}
                                    {searchResults.length > 0 && (
                                        <div className="mt-1 max-h-48 overflow-y-auto rounded-md border">
                                            {searchResults.map((p) => (
                                                <button key={p.id} type="button"
                                                    onClick={() => { setProduct(p); setSearchResults([]); setSearchQ(''); }}
                                                    className="w-full border-b px-3 py-2 text-left text-sm last:border-b-0 hover:bg-muted">
                                                    <div className="font-medium">{p.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {p.sku} · {p.type} · HPP {rupiah(p.cost_avg)}
                                                    </div>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </>
                            )}
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="a-qty">
                                    Qty (+ untuk tambah, − untuk kurang) *
                                </Label>
                                <Input id="a-qty" type="number" step="0.01" value={qty}
                                    onChange={(e) => setQty(e.target.value)}
                                    placeholder="cth: -2 atau 5" required />
                            </div>
                            <div>
                                <Label htmlFor="a-reason">Kategori *</Label>
                                <select id="a-reason" value={reason}
                                    onChange={(e) => setReason(e.target.value as typeof reason)}
                                    required
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                    <option value="rusak">Rusak</option>
                                    <option value="hilang">Hilang</option>
                                    <option value="expired">Kadaluwarsa</option>
                                    <option value="koreksi">Koreksi</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="a-notes">Catatan (opsional)</Label>
                            <Input id="a-notes" value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                maxLength={500}
                                placeholder="cth: kemasan robek saat unloading" />
                        </div>

                        {preview && (
                            <Card>
                                <CardContent className="space-y-2 p-3 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Stok saat ini di gudang</span>
                                        <span className="font-mono">{formatQty(preview.current_qty)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">HPP rata-rata</span>
                                        <span>{rupiah(preview.cost_avg)}</span>
                                    </div>
                                    <div className="flex justify-between border-t pt-1">
                                        <span className="font-medium">
                                            Nilai jurnal yang akan di-post
                                        </span>
                                        <span className="font-semibold">
                                            {preview.amount > 0
                                                ? rupiah(preview.amount)
                                                : <span className="text-amber-700">Rp 0 (HPP belum ada — jurnal di-skip)</span>}
                                        </span>
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {preview.is_plus
                                            ? 'D 1201 Persediaan / C 5100 HPP'
                                            : 'D 5100 HPP / C 1201 Persediaan'}
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        <DialogFooter>
                            <Button type="button" variant="ghost"
                                onClick={() => setOpen(false)} disabled={submitting}>
                                Batal
                            </Button>
                            <Button type="submit"
                                disabled={submitting || ! product || ! warehouseId || ! qty || parseFloat(qty) === 0}>
                                {submitting ? 'Menyimpan…' : 'Simpan Penyesuaian'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
