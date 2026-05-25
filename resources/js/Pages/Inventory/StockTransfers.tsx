import { useEffect, useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
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

interface Warehouse { id: number; code: string; name: string }

interface ProductLite {
    id: number;
    sku: string;
    name: string;
    type: string;
    source_qty: number;
    source_cost_avg: number;
}

interface TransferRow {
    id: number;
    transfer_no: string;
    status: 'in_transit' | 'completed' | 'cancelled';
    shipped_at: string;
    received_at: string | null;
    items_count: number;
    notes: string | null;
    source_warehouse?: Warehouse;
    dest_warehouse?: Warehouse;
    shipper?: { id: number; name: string };
    receiver?: { id: number; name: string } | null;
}

interface Paginated {
    data: TransferRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Filters {
    source_warehouse_id: number | null;
    dest_warehouse_id: number | null;
    status: string | null;
    from: string | null;
    to: string | null;
}

interface Props {
    transfers: Paginated;
    warehouses: Warehouse[];
    filters: Filters;
    currentUserWarehouseId: number | null;
}

interface DraftItem {
    key: string;
    product: ProductLite;
    qty: string;
}

const STATUS_VARIANT: Record<string, 'info' | 'success' | 'muted'> = {
    in_transit: 'info',
    completed: 'success',
    cancelled: 'muted',
};

const STATUS_LABEL: Record<string, string> = {
    in_transit: 'Dalam Perjalanan',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
};

function uid() { return Math.random().toString(36).slice(2, 9); }

export default function StockTransfers({
    transfers,
    warehouses,
    filters,
    currentUserWarehouseId,
}: Props) {
    // ── Filter form ────────────────────────────────────────────────
    const [fSource, setFSource] = useState(filters.source_warehouse_id ? String(filters.source_warehouse_id) : '');
    const [fDest, setFDest] = useState(filters.dest_warehouse_id ? String(filters.dest_warehouse_id) : '');
    const [fStatus, setFStatus] = useState(filters.status ?? '');
    const [fFrom, setFFrom] = useState(filters.from ?? '');
    const [fTo, setFTo] = useState(filters.to ?? '');

    function applyFilters(e?: FormEvent) {
        e?.preventDefault();
        router.get(route('inventory.transfers.index'), {
            source_warehouse_id: fSource || undefined,
            dest_warehouse_id: fDest || undefined,
            status: fStatus || undefined,
            from: fFrom || undefined,
            to: fTo || undefined,
        }, { preserveScroll: true, preserveState: true });
    }

    function resetFilters() {
        setFSource(''); setFDest(''); setFStatus(''); setFFrom(''); setFTo('');
        router.get(route('inventory.transfers.index'), {}, { preserveScroll: true });
    }

    // ── Create modal ───────────────────────────────────────────────
    const [open, setOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [sourceWh, setSourceWh] = useState(currentUserWarehouseId ? String(currentUserWarehouseId) : '');
    const [destWh, setDestWh] = useState('');
    const [items, setItems] = useState<DraftItem[]>([]);
    const [searchQ, setSearchQ] = useState('');
    const [searchResults, setSearchResults] = useState<ProductLite[]>([]);
    const [searching, setSearching] = useState(false);
    const [notes, setNotes] = useState('');

    function resetForm() {
        setSourceWh(currentUserWarehouseId ? String(currentUserWarehouseId) : '');
        setDestWh(''); setItems([]); setSearchQ(''); setSearchResults([]); setNotes('');
    }

    function startCreate() {
        resetForm();
        setOpen(true);
    }

    // Debounced product search untuk source warehouse.
    useEffect(() => {
        if (searchQ.trim().length < 2 || ! sourceWh) {
            setSearchResults([]);
            return;
        }
        setSearching(true);
        const ctrl = new AbortController();
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.get<{ results: ProductLite[] }>(
                    route('inventory.transfers.products.search'),
                    { params: { q: searchQ, source_warehouse_id: sourceWh }, signal: ctrl.signal },
                );
                setSearchResults(data.results);
            } catch (e) {
                if (! axios.isCancel(e)) setSearchResults([]);
            } finally {
                setSearching(false);
            }
        }, 250);
        return () => { clearTimeout(t); ctrl.abort(); };
    }, [searchQ, sourceWh]);

    function addItem(p: ProductLite) {
        // Cegah duplikat — schema unique (transfer_id, product_id).
        if (items.some((i) => i.product.id === p.id)) {
            toast.error(`${p.name} sudah ada di daftar`);
            return;
        }
        setItems([...items, { key: uid(), product: p, qty: '' }]);
        setSearchQ('');
        setSearchResults([]);
    }

    function updateItemQty(key: string, qty: string) {
        setItems(items.map((i) => i.key === key ? { ...i, qty } : i));
    }

    function removeItem(key: string) {
        setItems(items.filter((i) => i.key !== key));
    }

    const totalValue = items.reduce((sum, i) => {
        const q = parseFloat(i.qty) || 0;
        return sum + q * i.product.source_cost_avg;
    }, 0);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (! sourceWh || ! destWh || items.length === 0) return;
        if (items.some((i) => ! i.qty || parseFloat(i.qty) <= 0)) {
            toast.error('Semua item harus punya qty > 0');
            return;
        }
        setSubmitting(true);
        router.post(route('inventory.transfers.store'), {
            source_warehouse_id: Number(sourceWh),
            dest_warehouse_id: Number(destWh),
            notes: notes.trim() || null,
            items: items.map((i) => ({
                product_id: i.product.id,
                qty: parseFloat(i.qty),
            })),
        }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Transfer dikirim');
                setOpen(false);
                resetForm();
            },
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
            onFinish: () => setSubmitting(false),
        });
    }

    const destOptions = warehouses.filter((w) => String(w.id) !== sourceWh);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Transfer Stok</h2>}
        >
            <Head title="Transfer Stok" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm text-muted-foreground">
                        Transfer antar gudang 2-step (kirim → terima). Selisih saat terima
                        otomatis jadi kerugian transit (D 5100 HPP).
                    </h3>
                    <Button type="button" onClick={startCreate}>+ Buat Transfer</Button>
                </div>

                <Card>
                    <CardContent className="p-4">
                        <form onSubmit={applyFilters} className="grid grid-cols-1 gap-3 md:grid-cols-6">
                            <div>
                                <Label htmlFor="f-src">Gudang Asal</Label>
                                <select id="f-src" value={fSource}
                                    onChange={(e) => setFSource(e.target.value)}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                    <option value="">— Semua —</option>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>{w.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-dst">Gudang Tujuan</Label>
                                <select id="f-dst" value={fDest}
                                    onChange={(e) => setFDest(e.target.value)}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                    <option value="">— Semua —</option>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>{w.name}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-st">Status</Label>
                                <select id="f-st" value={fStatus}
                                    onChange={(e) => setFStatus(e.target.value)}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                    <option value="">— Semua —</option>
                                    <option value="in_transit">Dalam Perjalanan</option>
                                    <option value="completed">Selesai</option>
                                    <option value="cancelled">Dibatalkan</option>
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-from">Dari</Label>
                                <Input id="f-from" type="date" value={fFrom}
                                    onChange={(e) => setFFrom(e.target.value)} />
                            </div>
                            <div>
                                <Label htmlFor="f-to">Sampai</Label>
                                <Input id="f-to" type="date" value={fTo}
                                    onChange={(e) => setFTo(e.target.value)} />
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
                                    <TableHead>No Transfer</TableHead>
                                    <TableHead>Tanggal Kirim</TableHead>
                                    <TableHead>Asal → Tujuan</TableHead>
                                    <TableHead className="text-right">Item</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Pengirim / Penerima</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {transfers.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground">
                                            Belum ada transfer dengan filter ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {transfers.data.map((t) => (
                                    <TableRow key={t.id}>
                                        <TableCell className="font-mono text-sm">{t.transfer_no}</TableCell>
                                        <TableCell className="text-sm whitespace-nowrap">
                                            {formatDateID(t.shipped_at)}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            <div>{t.source_warehouse?.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                → {t.dest_warehouse?.name}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">{t.items_count}</TableCell>
                                        <TableCell>
                                            <Badge variant={STATUS_VARIANT[t.status] ?? 'muted'}>
                                                {STATUS_LABEL[t.status] ?? t.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            <div>{t.shipper?.name ?? '—'}</div>
                                            <div className="text-muted-foreground">
                                                {t.receiver?.name ?? (t.status === 'in_transit' ? '(belum diterima)' : '—')}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Link
                                                href={route('inventory.transfers.show', t.id)}
                                                className="text-sm text-sky-700 hover:underline"
                                            >
                                                Detail →
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {transfers.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>{transfers.from}–{transfers.to} dari {transfers.total}</div>
                        <div className="flex gap-1">
                            {transfers.links.map((l, i) => (
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
                <DialogContent className="max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Buat Transfer Stok</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="t-src">Gudang Asal *</Label>
                                <select id="t-src" value={sourceWh}
                                    onChange={(e) => { setSourceWh(e.target.value); setItems([]); }}
                                    required
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                    <option value="">— Pilih —</option>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>{w.name} ({w.code})</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="t-dst">Gudang Tujuan *</Label>
                                <select id="t-dst" value={destWh}
                                    onChange={(e) => setDestWh(e.target.value)}
                                    required disabled={! sourceWh}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base disabled:opacity-50">
                                    <option value="">— Pilih —</option>
                                    {destOptions.map((w) => (
                                        <option key={w.id} value={w.id}>{w.name} ({w.code})</option>
                                    ))}
                                </select>
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="t-search">Tambah Produk</Label>
                            <Input id="t-search" value={searchQ}
                                onChange={(e) => setSearchQ(e.target.value)}
                                disabled={! sourceWh}
                                placeholder={sourceWh ? 'Ketik SKU/nama (min 2 huruf)…' : 'Pilih gudang asal dulu'} />
                            {searching && (
                                <p className="mt-1 text-xs text-muted-foreground">Mencari…</p>
                            )}
                            {searchResults.length > 0 && (
                                <div className="mt-1 max-h-48 overflow-y-auto rounded-md border">
                                    {searchResults.map((p) => (
                                        <button key={p.id} type="button"
                                            onClick={() => addItem(p)}
                                            disabled={p.source_qty <= 0}
                                            className="w-full border-b px-3 py-2 text-left text-sm last:border-b-0 hover:bg-muted disabled:opacity-40">
                                            <div className="flex justify-between">
                                                <div>
                                                    <div className="font-medium">{p.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {p.sku} · HPP {rupiah(p.source_cost_avg)}
                                                    </div>
                                                </div>
                                                <div className="text-right text-xs">
                                                    <div className={p.source_qty > 0 ? 'text-emerald-700' : 'text-red-700'}>
                                                        Stok: {formatQty(p.source_qty)}
                                                    </div>
                                                </div>
                                            </div>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>

                        {items.length > 0 && (
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Produk</TableHead>
                                            <TableHead className="text-right">Stok Asal</TableHead>
                                            <TableHead className="text-right">Qty Kirim</TableHead>
                                            <TableHead className="text-right">HPP</TableHead>
                                            <TableHead className="text-right">Nilai</TableHead>
                                            <TableHead></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {items.map((i) => {
                                            const q = parseFloat(i.qty) || 0;
                                            const overStock = q > i.product.source_qty;
                                            return (
                                                <TableRow key={i.key}>
                                                    <TableCell>
                                                        <div className="font-medium">{i.product.name}</div>
                                                        <div className="text-xs text-muted-foreground">{i.product.sku}</div>
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono">
                                                        {formatQty(i.product.source_qty)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Input type="number" step="0.01" min="0"
                                                            max={i.product.source_qty}
                                                            value={i.qty}
                                                            onChange={(e) => updateItemQty(i.key, e.target.value)}
                                                            className={`w-28 text-right ${overStock ? 'border-red-500' : ''}`} />
                                                    </TableCell>
                                                    <TableCell className="text-right text-xs">
                                                        {rupiah(i.product.source_cost_avg)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {rupiah(q * i.product.source_cost_avg)}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        <Button type="button" size="sm" variant="ghost"
                                                            onClick={() => removeItem(i.key)}>
                                                            ×
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                                <div className="border-t bg-muted/30 px-4 py-2 text-sm">
                                    <div className="flex justify-between">
                                        <span className="font-medium">Total Nilai Kirim (BDP):</span>
                                        <span className="font-semibold">{rupiah(totalValue)}</span>
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Jurnal saat kirim: D 1203 BDP / C 1201 Persediaan
                                    </div>
                                </div>
                            </div>
                        )}

                        <div>
                            <Label htmlFor="t-notes">Catatan (opsional)</Label>
                            <Input id="t-notes" value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                maxLength={500}
                                placeholder="cth: relokasi stok rekomendasi vaksin" />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="ghost"
                                onClick={() => setOpen(false)} disabled={submitting}>
                                Batal
                            </Button>
                            <Button type="submit"
                                disabled={submitting || ! sourceWh || ! destWh || items.length === 0}>
                                {submitting ? 'Mengirim…' : 'Kirim Transfer'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
