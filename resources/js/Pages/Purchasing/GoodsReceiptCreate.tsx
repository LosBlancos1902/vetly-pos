import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { rupiah } from '@/lib/utils';

interface SupplierLite {
    id: number;
    code: string;
    name: string;
    payment_term_days: number;
}
interface WarehouseLite {
    id: number;
    code: string;
    name: string;
}
interface UnitLite {
    id: number;
    code: string;
    name: string;
}
interface ProductLite {
    id: number;
    sku: string;
    name: string;
}
interface POItem {
    id: number;
    product_id: number;
    unit_id: number;
    qty_ordered: string;
    qty_received: string;
    unit_price: string;
    product: ProductLite;
    unit: UnitLite;
}
interface PO {
    id: number;
    po_no: string;
    payment_type: 'cash' | 'tempo';
    payment_term_days: number;
    supplier: SupplierLite;
    warehouse: WarehouseLite;
    items: POItem[];
}

interface Props {
    po: PO;
}

interface FormLine {
    po_item_id: number;
    unit_id: string;
    qty_received: string;
}

export default function GoodsReceiptCreate({ po }: Props) {
    const remaining = (it: POItem) => {
        const r = Number(it.qty_ordered) - Number(it.qty_received);
        return r > 0 ? r : 0;
    };

    const [lines, setLines] = useState<FormLine[]>(
        po.items.map((it) => ({
            po_item_id: it.id,
            unit_id: String(it.unit_id),
            qty_received: String(remaining(it)),
        })),
    );
    const [receivedAt, setReceivedAt] = useState(new Date().toISOString().slice(0, 10));
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);

    function updateLine(idx: number, patch: Partial<FormLine>) {
        setLines((prev) => prev.map((l, i) => (i === idx ? { ...l, ...patch } : l)));
    }

    const total = lines.reduce((sum, l, i) => {
        const po_item = po.items[i];
        return sum + (Number(l.qty_received) || 0) * Number(po_item.unit_price);
    }, 0);

    function submit(e: FormEvent) {
        e.preventDefault();

        // Skip zero-qty lines.
        const activeLines = lines.filter((l) => Number(l.qty_received) > 0);
        if (activeLines.length === 0) {
            toast.error('Minimal satu item dengan qty > 0');
            return;
        }

        setSubmitting(true);
        router.post(
            route('purchasing.receipts.store'),
            {
                po_id: po.id,
                received_at: receivedAt,
                notes: notes || null,
                items: activeLines.map((l) => ({
                    po_item_id: l.po_item_id,
                    unit_id: Number(l.unit_id),
                    qty_received: Number(l.qty_received),
                })),
            },
            {
                onSuccess: () => toast.success('Penerimaan dicatat'),
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal terima'),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Terima Barang dari PO {po.po_no}</h2>}
        >
            <Head title={`Terima ${po.po_no}`} />

            <div className="mx-auto max-w-5xl space-y-4 p-4">
                <Card>
                    <CardContent className="space-y-2 p-4 text-sm">
                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <span className="text-muted-foreground">Supplier:</span>{' '}
                                {po.supplier.name}
                            </div>
                            <div>
                                <span className="text-muted-foreground">Warehouse:</span>{' '}
                                {po.warehouse.name}
                            </div>
                            <div>
                                <span className="text-muted-foreground">Pembayaran:</span>{' '}
                                {po.payment_type === 'cash'
                                    ? 'Cash'
                                    : `Tempo ${po.payment_term_days} hari`}
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <form onSubmit={submit} className="space-y-4">
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Produk</TableHead>
                                        <TableHead className="text-right">Ordered</TableHead>
                                        <TableHead className="text-right">Sudah Diterima</TableHead>
                                        <TableHead className="text-right">Sisa</TableHead>
                                        <TableHead className="text-right">Qty Terima</TableHead>
                                        <TableHead>Unit</TableHead>
                                        <TableHead className="text-right">Harga</TableHead>
                                        <TableHead className="text-right">Subtotal</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {po.items.map((it, idx) => {
                                        const line = lines[idx];
                                        const sub = (Number(line.qty_received) || 0) * Number(it.unit_price);
                                        return (
                                            <TableRow key={it.id}>
                                                <TableCell>
                                                    <div>{it.product.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {it.product.sku}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {Number(it.qty_ordered)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {Number(it.qty_received)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {remaining(it)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Input
                                                        type="number"
                                                        step="0.0001"
                                                        min="0"
                                                        value={line.qty_received}
                                                        onChange={(e) =>
                                                            updateLine(idx, {
                                                                qty_received: e.target.value,
                                                            })
                                                        }
                                                        className="w-24 text-right"
                                                    />
                                                </TableCell>
                                                <TableCell>{it.unit.code}</TableCell>
                                                <TableCell className="text-right">
                                                    {rupiah(it.unit_price)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {rupiah(sub)}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="space-y-3 p-4">
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="received-at">Tanggal Terima</Label>
                                    <Input
                                        id="received-at"
                                        type="date"
                                        value={receivedAt}
                                        onChange={(e) => setReceivedAt(e.target.value)}
                                        required
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="gr-notes">Catatan</Label>
                                    <Input
                                        id="gr-notes"
                                        value={notes}
                                        onChange={(e) => setNotes(e.target.value)}
                                    />
                                </div>
                            </div>

                            <div className="flex justify-between border-t pt-3">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => router.visit(route('purchasing.receipts.index'))}
                                >
                                    Batal
                                </Button>
                                <div className="flex items-center gap-4">
                                    <div className="text-right">
                                        <div className="text-sm text-muted-foreground">Total</div>
                                        <div className="text-xl font-semibold">{rupiah(total)}</div>
                                    </div>
                                    <Button type="submit" disabled={submitting}>
                                        {submitting ? 'Memproses…' : 'Terima Barang'}
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
