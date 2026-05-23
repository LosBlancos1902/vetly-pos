import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Card, CardContent } from '@/Components/ui/card';
import { rupiah } from '@/lib/utils';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';

interface SupplierLite {
    id: number;
    code: string;
    name: string;
}
interface POLite {
    id: number;
    po_no: string;
    supplier_id: number;
    payment_type: 'cash' | 'tempo';
    payment_term_days: number;
    supplier?: SupplierLite;
}
interface GRItem {
    id: number;
    qty_received: string;
    unit_price: string;
    subtotal: string;
}
interface GR {
    id: number;
    gr_no: string;
    received_at: string;
    total: string;
    notes: string | null;
    purchase_order?: POLite;
    warehouse?: { id: number; code: string; name: string };
    receiver?: { id: number; name: string };
    journal?: { id: number; journal_no: string };
    items: GRItem[];
}

interface PaginatedGRs {
    data: GR[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    receipts: PaginatedGRs;
    filters: { po_id?: number };
    receivablePos: POLite[];
}

export default function GoodsReceipts({ receipts, receivablePos }: Props) {
    const [selectedPoId, setSelectedPoId] = useState('');

    function startReceive() {
        if (!selectedPoId) return;
        router.visit(route('purchasing.receipts.create', selectedPoId));
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Penerimaan Barang</h2>}>
            <Head title="Penerimaan Barang" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Label htmlFor="po-select">Terima PO:</Label>
                        <select
                            id="po-select"
                            value={selectedPoId}
                            onChange={(e) => setSelectedPoId(e.target.value)}
                            className="flex h-10 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">— Pilih PO approved —</option>
                            {receivablePos.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.po_no} · {p.supplier?.name ?? '-'} ·{' '}
                                    {p.payment_type === 'cash' ? 'Cash' : `Tempo ${p.payment_term_days}h`}
                                </option>
                            ))}
                        </select>
                        <Button onClick={startReceive} disabled={!selectedPoId}>
                            Buat Penerimaan
                        </Button>
                    </div>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>GR No</TableHead>
                                    <TableHead>PO</TableHead>
                                    <TableHead>Supplier</TableHead>
                                    <TableHead>Tgl Terima</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                    <TableHead>Jurnal</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {receipts.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                            Belum ada penerimaan.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {receipts.data.map((gr) => (
                                    <TableRow key={gr.id}>
                                        <TableCell className="font-mono text-xs">{gr.gr_no}</TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {gr.purchase_order?.po_no ?? '-'}
                                        </TableCell>
                                        <TableCell>{gr.purchase_order?.supplier?.name ?? '-'}</TableCell>
                                        <TableCell>{gr.received_at}</TableCell>
                                        <TableCell className="text-right">{rupiah(gr.total)}</TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {gr.journal?.journal_no ?? '-'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {receipts.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>
                            {receipts.from}–{receipts.to} dari {receipts.total}
                        </div>
                        <div className="flex gap-1">
                            {receipts.links.map((l, i) => (
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

                <div className="text-sm text-muted-foreground">
                    <Link href={route('purchasing.orders.index')} className="underline">
                        ← kembali ke Purchase Order
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
