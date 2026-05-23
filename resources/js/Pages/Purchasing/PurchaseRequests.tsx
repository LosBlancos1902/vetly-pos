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

type Status = 'draft' | 'submitted' | 'approved' | 'rejected';

interface WarehouseLite {
    id: number;
    code: string;
    name: string;
}

interface UserLite {
    id: number;
    name: string;
}

interface PRItem {
    id: number;
    product_id: number;
    qty: string;
    satuan: string;
    alasan: string | null;
    product?: { id: number; sku: string; name: string };
}

interface PR {
    id: number;
    pr_no: string;
    requester_id: number;
    warehouse_id: number;
    status: Status;
    notes: string | null;
    approved_by: number | null;
    approved_at: string | null;
    rejected_reason: string | null;
    created_at: string;
    requester: UserLite;
    warehouse: WarehouseLite;
    approver: UserLite | null;
    items: PRItem[];
}

interface PaginatedPRs {
    data: PR[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    requests: PaginatedPRs;
    filters: { status?: Status };
    warehouses: WarehouseLite[];
    defaultWarehouseId: number | null;
}

interface ItemRow {
    product_id: string;
    product_label: string;
    qty: string;
    satuan: string;
    alasan: string;
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

const EMPTY_ITEM: ItemRow = { product_id: '', product_label: '', qty: '', satuan: '', alasan: '' };

export default function PurchaseRequests({ requests, filters, warehouses, defaultWarehouseId }: Props) {
    const { auth } = usePage().props;
    const userId = auth.user.id;
    const canCreate = auth.permissions?.includes('purchasing.pr_create') ?? false;
    const canApprove = auth.permissions?.includes('purchasing.pr_approve') ?? false;

    const [createOpen, setCreateOpen] = useState(false);
    const [detailPr, setDetailPr] = useState<PR | null>(null);
    const [rejectPr, setRejectPr] = useState<PR | null>(null);
    const [rejectReason, setRejectReason] = useState('');

    const [form, setForm] = useState({
        warehouse_id: String(defaultWarehouseId ?? ''),
        notes: '',
        items: [{ ...EMPTY_ITEM }] as ItemRow[],
    });

    const selectableWarehouses = useMemo(
        () => warehouses.filter((w) => !defaultWarehouseId || w.id === defaultWarehouseId || defaultWarehouseId === null),
        [warehouses, defaultWarehouseId],
    );

    function startCreate() {
        setForm({
            warehouse_id: String(defaultWarehouseId ?? ''),
            notes: '',
            items: [{ ...EMPTY_ITEM }],
        });
        setCreateOpen(true);
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

    function submitCreate(e: FormEvent) {
        e.preventDefault();
        router.post(
            route('purchasing.requests.store'),
            {
                warehouse_id: Number(form.warehouse_id),
                notes: form.notes || null,
                items: form.items.map((r) => ({
                    product_id: Number(r.product_id),
                    qty: Number(r.qty),
                    satuan: r.satuan,
                    alasan: r.alasan || null,
                })),
            },
            {
                onSuccess: () => {
                    toast.success('PR draft dibuat');
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

    function doSubmit(pr: PR) {
        router.post(
            route('purchasing.requests.submit', pr.id),
            {},
            {
                onSuccess: () => toast.success(`${pr.pr_no} disubmit`),
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal submit'),
                preserveScroll: true,
            },
        );
    }

    function doApprove(pr: PR) {
        if (!confirm(`Approve PR ${pr.pr_no}?`)) return;
        router.post(
            route('purchasing.requests.approve', pr.id),
            {},
            {
                onSuccess: () => toast.success(`${pr.pr_no} disetujui`),
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal approve'),
                preserveScroll: true,
            },
        );
    }

    function startReject(pr: PR) {
        setRejectPr(pr);
        setRejectReason('');
    }

    function doReject(e: FormEvent) {
        e.preventDefault();
        if (!rejectPr) return;
        router.post(
            route('purchasing.requests.reject', rejectPr.id),
            { rejected_reason: rejectReason },
            {
                onSuccess: () => {
                    toast.success(`${rejectPr.pr_no} ditolak`);
                    setRejectPr(null);
                },
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal reject'),
                preserveScroll: true,
            },
        );
    }

    function filterByStatus(s: Status | '') {
        router.get(
            route('purchasing.requests.index'),
            s ? { status: s } : {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Purchase Request</h2>}>
            <Head title="Purchase Request" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                        <Label htmlFor="status-filter">Status:</Label>
                        <select
                            id="status-filter"
                            value={filters.status ?? ''}
                            onChange={(e) => filterByStatus(e.target.value as Status | '')}
                            className="flex h-10 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Semua</option>
                            <option value="draft">Draft</option>
                            <option value="submitted">Submitted</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                    {canCreate && (
                        <Button onClick={startCreate} className="min-h-11">
                            + Buat PR
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>PR No</TableHead>
                                    <TableHead>Requester</TableHead>
                                    <TableHead>Warehouse</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Item</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {requests.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                            Belum ada PR.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {requests.data.map((pr) => {
                                    const meta = STATUS_LABEL[pr.status];
                                    const isMine = pr.requester_id === userId;
                                    return (
                                        <TableRow key={pr.id}>
                                            <TableCell className="font-mono text-xs">{pr.pr_no}</TableCell>
                                            <TableCell>{pr.requester?.name ?? '-'}</TableCell>
                                            <TableCell>{pr.warehouse?.name ?? '-'}</TableCell>
                                            <TableCell>
                                                <Badge variant={meta.variant}>{meta.label}</Badge>
                                            </TableCell>
                                            <TableCell className="text-right">{pr.items.length}</TableCell>
                                            <TableCell className="text-right">
                                                <Button variant="ghost" size="sm" onClick={() => setDetailPr(pr)}>
                                                    Detail
                                                </Button>
                                                {pr.status === 'draft' && isMine && canCreate && (
                                                    <Button variant="ghost" size="sm" onClick={() => doSubmit(pr)}>
                                                        Submit
                                                    </Button>
                                                )}
                                                {pr.status === 'submitted' && canApprove && (
                                                    <>
                                                        <Button variant="ghost" size="sm" onClick={() => doApprove(pr)}>
                                                            Approve
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="text-destructive"
                                                            onClick={() => startReject(pr)}
                                                        >
                                                            Reject
                                                        </Button>
                                                    </>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {requests.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>
                            {requests.from}–{requests.to} dari {requests.total}
                        </div>
                        <div className="flex gap-1">
                            {requests.links.map((l, i) => (
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
                <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Buat Purchase Request</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitCreate} className="space-y-4">
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <Label htmlFor="pr-warehouse">Warehouse</Label>
                                <select
                                    id="pr-warehouse"
                                    value={form.warehouse_id}
                                    onChange={(e) =>
                                        setForm({ ...form, warehouse_id: e.target.value, items: [{ ...EMPTY_ITEM }] })
                                    }
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                    required
                                    disabled={defaultWarehouseId !== null}
                                >
                                    <option value="">— Pilih warehouse —</option>
                                    {selectableWarehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name} ({w.code})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="pr-notes">Catatan</Label>
                                <Input
                                    id="pr-notes"
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
                                        <div className="col-span-12 md:col-span-5">
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
                                        <div className="col-span-5 md:col-span-2">
                                            <Input
                                                type="number"
                                                step="0.0001"
                                                min="0.0001"
                                                placeholder="Qty"
                                                value={row.qty}
                                                onChange={(e) => updateRow(idx, { qty: e.target.value })}
                                                required
                                            />
                                        </div>
                                        <div className="col-span-5 md:col-span-2">
                                            <Input
                                                placeholder="Satuan (mis. pcs)"
                                                value={row.satuan}
                                                onChange={(e) => updateRow(idx, { satuan: e.target.value })}
                                                required
                                            />
                                        </div>
                                        <div className="col-span-10 md:col-span-2">
                                            <Input
                                                placeholder="Alasan"
                                                value={row.alasan}
                                                onChange={(e) => updateRow(idx, { alasan: e.target.value })}
                                            />
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
                                                Hapus
                                            </Button>
                                        </div>
                                    </div>
                                ))}
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
            <Dialog open={detailPr !== null} onOpenChange={(o) => !o && setDetailPr(null)}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{detailPr?.pr_no}</DialogTitle>
                    </DialogHeader>
                    {detailPr && (
                        <div className="space-y-3 text-sm">
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <span className="text-muted-foreground">Requester:</span>{' '}
                                    {detailPr.requester?.name}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Warehouse:</span>{' '}
                                    {detailPr.warehouse?.name}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Status:</span>{' '}
                                    <Badge variant={STATUS_LABEL[detailPr.status].variant}>
                                        {STATUS_LABEL[detailPr.status].label}
                                    </Badge>
                                </div>
                                {detailPr.approver && (
                                    <div>
                                        <span className="text-muted-foreground">Approver:</span>{' '}
                                        {detailPr.approver.name}
                                    </div>
                                )}
                            </div>
                            {detailPr.notes && (
                                <div>
                                    <span className="text-muted-foreground">Catatan:</span> {detailPr.notes}
                                </div>
                            )}
                            {detailPr.rejected_reason && (
                                <div className="rounded-md bg-destructive/10 p-2 text-destructive">
                                    <span className="font-medium">Alasan reject:</span> {detailPr.rejected_reason}
                                </div>
                            )}
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Produk</TableHead>
                                        <TableHead className="text-right">Qty</TableHead>
                                        <TableHead>Satuan</TableHead>
                                        <TableHead>Alasan</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {detailPr.items.map((it) => (
                                        <TableRow key={it.id}>
                                            <TableCell>
                                                <div>{it.product?.name ?? '#'+it.product_id}</div>
                                                {it.product?.sku && (
                                                    <div className="text-xs text-muted-foreground">{it.product.sku}</div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">{Number(it.qty)}</TableCell>
                                            <TableCell>{it.satuan}</TableCell>
                                            <TableCell>{it.alasan ?? '-'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {/* Reject dialog */}
            <Dialog open={rejectPr !== null} onOpenChange={(o) => !o && setRejectPr(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Reject PR {rejectPr?.pr_no}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={doReject} className="space-y-3">
                        <div>
                            <Label htmlFor="reject-reason">Alasan penolakan</Label>
                            <Input
                                id="reject-reason"
                                value={rejectReason}
                                onChange={(e) => setRejectReason(e.target.value)}
                                required
                                minLength={3}
                                autoFocus
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setRejectPr(null)}>
                                Batal
                            </Button>
                            <Button type="submit" variant="destructive">
                                Reject
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
