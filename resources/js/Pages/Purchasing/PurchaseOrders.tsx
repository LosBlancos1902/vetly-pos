import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import axios from 'axios';
import { Head, router, usePage } from '@inertiajs/react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { rupiah } from '@/lib/utils';
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

type Status = 'draft' | 'submitted' | 'approved' | 'rejected' | 'cancelled' | 'received';
type PaymentType = 'cash' | 'tempo';

interface WarehouseLite {
    id: number;
    code: string;
    name: string;
}
interface SupplierLite {
    id: number;
    code: string;
    name: string;
    payment_term_days: number;
}
interface UserLite {
    id: number;
    name: string;
}
interface UnitLite {
    id: number;
    code: string;
    name: string;
}
interface POItem {
    id: number;
    product_id: number;
    unit_id: number;
    qty_ordered: string;
    qty_received: string;
    unit_price: string;
    subtotal: string;
    product?: { id: number; sku: string; name: string };
    unit?: UnitLite;
}
interface PO {
    id: number;
    po_no: string;
    pr_id: number | null;
    supplier_id: number;
    warehouse_id: number;
    payment_type: PaymentType;
    payment_term_days: number;
    status: Status;
    subtotal: string;
    total: string;
    notes: string | null;
    created_by: number;
    approved_by: number | null;
    approved_at: string | null;
    rejected_reason: string | null;
    cancelled_at: string | null;
    cancelled_reason: string | null;
    supplier: SupplierLite;
    warehouse: WarehouseLite;
    creator: UserLite;
    approver: UserLite | null;
    items: POItem[];
}

interface PaginatedPOs {
    data: PO[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    orders: PaginatedPOs;
    filters: { status?: Status; supplier_id?: number };
    suppliers: SupplierLite[];
    warehouses: WarehouseLite[];
    units: UnitLite[];
    defaultWarehouseId: number | null;
}

interface ItemRow {
    product_id: string;
    product_label: string;
    unit_id: string;
    qty_ordered: string;
    unit_price: string;
}

interface SearchProduct {
    id: number;
    sku: string;
    name: string;
}

const STATUS_LABEL: Record<Status, { label: string; variant: 'default' | 'info' | 'success' | 'destructive' | 'muted' }> = {
    draft: { label: 'Draft', variant: 'muted' },
    submitted: { label: 'Submitted', variant: 'info' },
    approved: { label: 'Approved', variant: 'success' },
    rejected: { label: 'Rejected', variant: 'destructive' },
    cancelled: { label: 'Cancelled', variant: 'destructive' },
    received: { label: 'Received', variant: 'success' },
};

function ProductPicker({
    warehouseId,
    onPick,
}: {
    warehouseId: number | null;
    onPick: (p: SearchProduct) => void;
}) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<SearchProduct[]>([]);
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const term = q.trim();

    useEffect(() => {
        if (term.length < 2 || !warehouseId) {
            setResults([]);
            setOpen(false);
            return;
        }
        const t = setTimeout(async () => {
            try {
                const res = await axios.get(route('pos.search'), {
                    params: { q: term, warehouse_id: warehouseId },
                });
                setResults(res.data.results ?? []);
                setOpen(true);
            } catch (err) {
                console.error(err);
                toast.error('Gagal mencari produk');
            }
        }, 250);
        return () => clearTimeout(t);
    }, [term, warehouseId]);

    useEffect(() => {
        function onDocMouseDown(e: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', onDocMouseDown);
        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, []);

    function pick(p: SearchProduct) {
        onPick(p);
        setQ('');
        setResults([]);
        setOpen(false);
    }

    return (
        <div ref={containerRef} className="relative">
            <Input
                placeholder={warehouseId ? 'Cari produk (min. 2 huruf)' : 'Pilih warehouse dulu'}
                value={q}
                onChange={(e) => setQ(e.target.value)}
                disabled={!warehouseId}
            />
            {open && results.length > 0 && (
                <div className="absolute z-50 mt-1 w-full overflow-hidden rounded-md border bg-background shadow-lg">
                    <ul className="max-h-60 divide-y overflow-y-auto">
                        {results.map((p) => (
                            <li key={p.id}>
                                <button
                                    type="button"
                                    onClick={() => pick(p)}
                                    className="block w-full px-3 py-2 text-left hover:bg-accent"
                                >
                                    <div className="text-sm font-medium">{p.name}</div>
                                    <div className="text-xs text-muted-foreground">{p.sku}</div>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

const EMPTY_ITEM: ItemRow = { product_id: '', product_label: '', unit_id: '', qty_ordered: '', unit_price: '' };

interface FormState {
    supplier_id: string;
    warehouse_id: string;
    payment_type: PaymentType;
    payment_term_days: string;
    notes: string;
    items: ItemRow[];
}

export default function PurchaseOrders({
    orders,
    filters,
    suppliers,
    warehouses,
    units,
    defaultWarehouseId,
}: Props) {
    const { auth } = usePage().props;
    const userId = auth.user.id;
    const canCreate = auth.permissions?.includes('purchasing.po_create') ?? false;
    const canApprove = auth.permissions?.includes('purchasing.po_approve') ?? false;

    const [createOpen, setCreateOpen] = useState(false);
    const [detailPo, setDetailPo] = useState<PO | null>(null);
    const [rejectPo, setRejectPo] = useState<PO | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [cancelPo, setCancelPo] = useState<PO | null>(null);
    const [cancelReason, setCancelReason] = useState('');

    const initialForm: FormState = {
        supplier_id: '',
        warehouse_id: String(defaultWarehouseId ?? ''),
        payment_type: 'cash',
        payment_term_days: '0',
        notes: '',
        items: [{ ...EMPTY_ITEM }],
    };
    const [form, setForm] = useState<FormState>(initialForm);

    const supplierById = useMemo(() => new Map(suppliers.map((s) => [s.id, s])), [suppliers]);

    function startCreate() {
        setForm({
            ...initialForm,
            warehouse_id: String(defaultWarehouseId ?? ''),
            items: [{ ...EMPTY_ITEM }],
        });
        setCreateOpen(true);
    }

    function onSupplierChange(supplierId: string) {
        const s = supplierById.get(Number(supplierId));
        const term = s?.payment_term_days ?? 0;
        setForm({
            ...form,
            supplier_id: supplierId,
            payment_type: term > 0 ? 'tempo' : 'cash',
            payment_term_days: String(term),
        });
    }

    function onPaymentTypeChange(type: PaymentType) {
        setForm({
            ...form,
            payment_type: type,
            payment_term_days: type === 'cash' ? '0' : form.payment_term_days || '30',
        });
    }

    function addRow() {
        setForm({ ...form, items: [...form.items, { ...EMPTY_ITEM }] });
    }

    function removeRow(idx: number) {
        if (form.items.length === 1) return;
        setForm({ ...form, items: form.items.filter((_, i) => i !== idx) });
    }

    function updateRow(idx: number, patch: Partial<ItemRow>) {
        setForm({
            ...form,
            items: form.items.map((r, i) => (i === idx ? { ...r, ...patch } : r)),
        });
    }

    const totalAmount = form.items.reduce(
        (sum, r) => sum + (Number(r.qty_ordered) || 0) * (Number(r.unit_price) || 0),
        0,
    );

    function submitCreate(e: FormEvent) {
        e.preventDefault();
        router.post(
            route('purchasing.orders.store'),
            {
                supplier_id: Number(form.supplier_id),
                warehouse_id: Number(form.warehouse_id),
                payment_type: form.payment_type,
                payment_term_days: Number(form.payment_term_days || 0),
                notes: form.notes || null,
                items: form.items.map((r) => ({
                    product_id: Number(r.product_id),
                    unit_id: Number(r.unit_id),
                    qty_ordered: Number(r.qty_ordered),
                    unit_price: Number(r.unit_price),
                })),
            },
            {
                onSuccess: () => {
                    toast.success('PO draft dibuat');
                    setCreateOpen(false);
                },
                onError: (errs) => {
                    const first = Object.values(errs)[0];
                    if (first) toast.error(first as string);
                },
                preserveScroll: true,
            },
        );
    }

    function doAction(po: PO, action: 'submit' | 'approve') {
        const confirmMsg = action === 'submit' ? `Submit PO ${po.po_no}?` : `Approve PO ${po.po_no}?`;
        if (!confirm(confirmMsg)) return;
        router.post(
            route(`purchasing.orders.${action}`, po.id),
            {},
            {
                onSuccess: () => toast.success(`${po.po_no} ${action === 'submit' ? 'disubmit' : 'disetujui'}`),
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal'),
                preserveScroll: true,
            },
        );
    }

    function doReject(e: FormEvent) {
        e.preventDefault();
        if (!rejectPo) return;
        router.post(
            route('purchasing.orders.reject', rejectPo.id),
            { rejected_reason: rejectReason },
            {
                onSuccess: () => {
                    toast.success(`${rejectPo.po_no} ditolak`);
                    setRejectPo(null);
                },
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal reject'),
                preserveScroll: true,
            },
        );
    }

    function doCancel(e: FormEvent) {
        e.preventDefault();
        if (!cancelPo) return;
        router.post(
            route('purchasing.orders.cancel', cancelPo.id),
            { cancelled_reason: cancelReason },
            {
                onSuccess: () => {
                    toast.success(`${cancelPo.po_no} dibatalkan`);
                    setCancelPo(null);
                },
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal cancel'),
                preserveScroll: true,
            },
        );
    }

    function filterStatus(s: Status | '') {
        router.get(
            route('purchasing.orders.index'),
            s ? { ...filters, status: s } : { ...filters, status: undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Purchase Order</h2>}>
            <Head title="Purchase Order" />

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
                            <option value="submitted">Submitted</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="received">Received</option>
                        </select>
                    </div>
                    {canCreate && (
                        <Button onClick={startCreate} className="min-h-11">
                            + Buat PO
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>PO No</TableHead>
                                    <TableHead>Supplier</TableHead>
                                    <TableHead>Payment</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {orders.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                            Belum ada PO.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {orders.data.map((po) => {
                                    const meta = STATUS_LABEL[po.status];
                                    const isMine = po.created_by === userId;
                                    return (
                                        <TableRow key={po.id}>
                                            <TableCell className="font-mono text-xs">{po.po_no}</TableCell>
                                            <TableCell>{po.supplier?.name ?? '-'}</TableCell>
                                            <TableCell>
                                                {po.payment_type === 'cash'
                                                    ? 'Cash'
                                                    : `Tempo ${po.payment_term_days}h`}
                                            </TableCell>
                                            <TableCell className="text-right">{rupiah(po.total)}</TableCell>
                                            <TableCell>
                                                <Badge variant={meta.variant}>{meta.label}</Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button variant="ghost" size="sm" onClick={() => setDetailPo(po)}>
                                                    Detail
                                                </Button>
                                                {po.status === 'draft' && isMine && canCreate && (
                                                    <Button variant="ghost" size="sm" onClick={() => doAction(po, 'submit')}>
                                                        Submit
                                                    </Button>
                                                )}
                                                {po.status === 'submitted' && canApprove && (
                                                    <>
                                                        <Button variant="ghost" size="sm" onClick={() => doAction(po, 'approve')}>
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-destructive"
                                                            onClick={() => {
                                                                setRejectPo(po);
                                                                setRejectReason('');
                                                            }}
                                                        >
                                                            Reject
                                                        </Button>
                                                    </>
                                                )}
                                                {['draft', 'submitted', 'approved'].includes(po.status) && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-destructive"
                                                        onClick={() => {
                                                            setCancelPo(po);
                                                            setCancelReason('');
                                                        }}
                                                    >
                                                        Cancel
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {orders.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>
                            {orders.from}–{orders.to} dari {orders.total}
                        </div>
                        <div className="flex gap-1">
                            {orders.links.map((l, i) => (
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

            {/* Create dialog */}
            <Dialog open={createOpen} onOpenChange={setCreateOpen}>
                <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Buat Purchase Order</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <Label htmlFor="po-supplier">Supplier</Label>
                                <select
                                    id="po-supplier"
                                    value={form.supplier_id}
                                    onChange={(e) => onSupplierChange(e.target.value)}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                    required
                                >
                                    <option value="">— Pilih supplier —</option>
                                    {suppliers.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name} ({s.code})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="po-warehouse">Warehouse</Label>
                                <select
                                    id="po-warehouse"
                                    value={form.warehouse_id}
                                    onChange={(e) => setForm({ ...form, warehouse_id: e.target.value, items: [{ ...EMPTY_ITEM }] })}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                    required
                                    disabled={defaultWarehouseId !== null}
                                >
                                    <option value="">— Pilih warehouse —</option>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name} ({w.code})
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <Label>Pembayaran</Label>
                                <div className="mt-2 flex gap-4">
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="payment_type"
                                            value="cash"
                                            checked={form.payment_type === 'cash'}
                                            onChange={() => onPaymentTypeChange('cash')}
                                        />
                                        Cash
                                    </label>
                                    <label className="flex items-center gap-2">
                                        <input
                                            type="radio"
                                            name="payment_type"
                                            value="tempo"
                                            checked={form.payment_type === 'tempo'}
                                            onChange={() => onPaymentTypeChange('tempo')}
                                        />
                                        Tempo
                                    </label>
                                </div>
                            </div>
                            <div>
                                <Label htmlFor="po-term">Termin (hari)</Label>
                                <Input
                                    id="po-term"
                                    type="number"
                                    min="0"
                                    value={form.payment_term_days}
                                    onChange={(e) => setForm({ ...form, payment_term_days: e.target.value })}
                                    disabled={form.payment_type === 'cash'}
                                    required={form.payment_type === 'tempo'}
                                />
                            </div>

                            <div className="md:col-span-2">
                                <Label htmlFor="po-notes">Catatan</Label>
                                <Input
                                    id="po-notes"
                                    value={form.notes}
                                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Item</Label>
                                <Button type="button" variant="outline" size="sm" onClick={addRow}>
                                    + Tambah Item
                                </Button>
                            </div>

                            <div className="space-y-2">
                                {form.items.map((row, idx) => (
                                    <div key={idx} className="grid grid-cols-12 gap-2 rounded-md border p-2">
                                        <div className="col-span-12 md:col-span-4">
                                            {row.product_id ? (
                                                <div className="flex items-center justify-between rounded-md border bg-muted px-3 py-2 text-sm">
                                                    <span className="truncate">{row.product_label}</span>
                                                    <button
                                                        type="button"
                                                        onClick={() => updateRow(idx, { product_id: '', product_label: '' })}
                                                        className="text-xs text-destructive"
                                                    >
                                                        ganti
                                                    </button>
                                                </div>
                                            ) : (
                                                <ProductPicker
                                                    warehouseId={form.warehouse_id ? Number(form.warehouse_id) : null}
                                                    onPick={(p) =>
                                                        updateRow(idx, {
                                                            product_id: String(p.id),
                                                            product_label: `${p.name} (${p.sku})`,
                                                        })
                                                    }
                                                />
                                            )}
                                        </div>
                                        <div className="col-span-4 md:col-span-2">
                                            <select
                                                value={row.unit_id}
                                                onChange={(e) => updateRow(idx, { unit_id: e.target.value })}
                                                className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                                required
                                            >
                                                <option value="">— Unit —</option>
                                                {units.map((u) => (
                                                    <option key={u.id} value={u.id}>
                                                        {u.code}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="col-span-4 md:col-span-2">
                                            <Input
                                                type="number"
                                                step="0.0001"
                                                min="0.0001"
                                                placeholder="Qty"
                                                value={row.qty_ordered}
                                                onChange={(e) => updateRow(idx, { qty_ordered: e.target.value })}
                                                required
                                            />
                                        </div>
                                        <div className="col-span-4 md:col-span-2">
                                            <Input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                placeholder="Harga"
                                                value={row.unit_price}
                                                onChange={(e) => updateRow(idx, { unit_price: e.target.value })}
                                                required
                                            />
                                        </div>
                                        <div className="col-span-10 md:col-span-1 text-sm font-medium">
                                            {rupiah((Number(row.qty_ordered) || 0) * (Number(row.unit_price) || 0))}
                                        </div>
                                        <div className="col-span-2 md:col-span-1">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive"
                                                onClick={() => removeRow(idx)}
                                                disabled={form.items.length === 1}
                                            >
                                                X
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="flex justify-end pt-2 text-right">
                                <div>
                                    <div className="text-sm text-muted-foreground">Total</div>
                                    <div className="text-xl font-semibold">{rupiah(totalAmount)}</div>
                                </div>
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCreateOpen(false)}>
                                Batal
                            </Button>
                            <Button type="submit">Simpan Draft</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Detail dialog */}
            <Dialog open={detailPo !== null} onOpenChange={(o) => !o && setDetailPo(null)}>
                <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{detailPo?.po_no}</DialogTitle>
                    </DialogHeader>
                    {detailPo && (
                        <div className="space-y-3 text-sm">
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <span className="text-muted-foreground">Supplier:</span>{' '}
                                    {detailPo.supplier?.name}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Warehouse:</span>{' '}
                                    {detailPo.warehouse?.name}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Pembayaran:</span>{' '}
                                    {detailPo.payment_type === 'cash'
                                        ? 'Cash'
                                        : `Tempo ${detailPo.payment_term_days} hari`}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Status:</span>{' '}
                                    <Badge variant={STATUS_LABEL[detailPo.status].variant}>
                                        {STATUS_LABEL[detailPo.status].label}
                                    </Badge>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Pembuat:</span>{' '}
                                    {detailPo.creator?.name}
                                </div>
                                {detailPo.approver && (
                                    <div>
                                        <span className="text-muted-foreground">Approver:</span>{' '}
                                        {detailPo.approver.name}
                                    </div>
                                )}
                            </div>
                            {detailPo.notes && (
                                <div>
                                    <span className="text-muted-foreground">Catatan:</span> {detailPo.notes}
                                </div>
                            )}
                            {detailPo.rejected_reason && (
                                <div className="rounded-md bg-destructive/10 p-2 text-destructive">
                                    <span className="font-medium">Alasan reject:</span> {detailPo.rejected_reason}
                                </div>
                            )}
                            {detailPo.cancelled_reason && (
                                <div className="rounded-md bg-destructive/10 p-2 text-destructive">
                                    <span className="font-medium">Alasan cancel:</span> {detailPo.cancelled_reason}
                                </div>
                            )}
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Produk</TableHead>
                                        <TableHead className="text-right">Qty</TableHead>
                                        <TableHead>Unit</TableHead>
                                        <TableHead className="text-right">Harga</TableHead>
                                        <TableHead className="text-right">Subtotal</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {detailPo.items.map((it) => (
                                        <TableRow key={it.id}>
                                            <TableCell>
                                                <div>{it.product?.name ?? '#'+it.product_id}</div>
                                                {it.product?.sku && (
                                                    <div className="text-xs text-muted-foreground">{it.product.sku}</div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">{Number(it.qty_ordered)}</TableCell>
                                            <TableCell>{it.unit?.code ?? '-'}</TableCell>
                                            <TableCell className="text-right">{rupiah(it.unit_price)}</TableCell>
                                            <TableCell className="text-right">{rupiah(it.subtotal)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <div className="flex justify-end text-right">
                                <div>
                                    <div className="text-sm text-muted-foreground">Total</div>
                                    <div className="text-xl font-semibold">{rupiah(detailPo.total)}</div>
                                </div>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Reject dialog */}
            <Dialog open={rejectPo !== null} onOpenChange={(o) => !o && setRejectPo(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reject PO {rejectPo?.po_no}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={doReject} className="space-y-3">
                        <div>
                            <Label htmlFor="po-reject-reason">Alasan penolakan</Label>
                            <Input
                                id="po-reject-reason"
                                value={rejectReason}
                                onChange={(e) => setRejectReason(e.target.value)}
                                required
                                minLength={3}
                                autoFocus
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setRejectPo(null)}>
                                Batal
                            </Button>
                            <Button type="submit" variant="destructive">
                                Reject
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Cancel dialog */}
            <Dialog open={cancelPo !== null} onOpenChange={(o) => !o && setCancelPo(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Batalkan PO {cancelPo?.po_no}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={doCancel} className="space-y-3">
                        <div>
                            <Label htmlFor="po-cancel-reason">Alasan pembatalan</Label>
                            <Input
                                id="po-cancel-reason"
                                value={cancelReason}
                                onChange={(e) => setCancelReason(e.target.value)}
                                required
                                minLength={3}
                                autoFocus
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCancelPo(null)}>
                                Tutup
                            </Button>
                            <Button type="submit" variant="destructive">
                                Batalkan
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
