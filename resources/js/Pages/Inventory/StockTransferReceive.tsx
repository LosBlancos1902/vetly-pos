import { useMemo, useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
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
interface Product { id: number; sku: string; name: string; type: string }

interface TransferItem {
    id: number;
    product_id: number;
    qty_sent: string;
    qty_received: string | null;
    cost_at_transfer: string;
    variance_notes: string | null;
    product?: Product;
}

interface Transfer {
    id: number;
    transfer_no: string;
    status: 'in_transit' | 'completed' | 'cancelled';
    shipped_at: string;
    received_at: string | null;
    notes: string | null;
    receive_notes: string | null;
    source_warehouse?: Warehouse;
    dest_warehouse?: Warehouse;
    shipper?: { id: number; name: string };
    receiver?: { id: number; name: string } | null;
    items: TransferItem[];
    journal_ship?: { id: number; journal_no: string; date: string } | null;
    journal_receive?: { id: number; journal_no: string; date: string } | null;
}

interface Props {
    transfer: Transfer;
    canReceive: boolean;
}

interface ReceiveLine {
    id: number;
    qty_received: string;
    variance_notes: string;
}

const STATUS_VARIANT: Record<string, 'info' | 'success' | 'muted'> = {
    in_transit: 'info', completed: 'success', cancelled: 'muted',
};
const STATUS_LABEL: Record<string, string> = {
    in_transit: 'Dalam Perjalanan', completed: 'Selesai', cancelled: 'Dibatalkan',
};

export default function StockTransferReceive({ transfer, canReceive }: Props) {
    const isInTransit = transfer.status === 'in_transit';

    // Receive form state — default qty_received = qty_sent (terima penuh).
    const [lines, setLines] = useState<Record<number, ReceiveLine>>(() => {
        const m: Record<number, ReceiveLine> = {};
        transfer.items.forEach((it) => {
            m[it.id] = {
                id: it.id,
                qty_received: isInTransit ? it.qty_sent : (it.qty_received ?? '0'),
                variance_notes: it.variance_notes ?? '',
            };
        });
        return m;
    });
    const [receiveNotes, setReceiveNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);

    function updateLine(id: number, patch: Partial<ReceiveLine>) {
        setLines((prev) => ({ ...prev, [id]: { ...prev[id], ...patch } }));
    }

    const totals = useMemo(() => {
        let totalReceived = 0;
        let totalLoss = 0;
        transfer.items.forEach((it) => {
            const cost = parseFloat(it.cost_at_transfer);
            const sent = parseFloat(it.qty_sent);
            const recv = parseFloat(lines[it.id]?.qty_received || '0');
            const validRecv = isNaN(recv) ? 0 : Math.max(0, Math.min(recv, sent));
            totalReceived += validRecv * cost;
            totalLoss += (sent - validRecv) * cost;
        });
        return { totalReceived, totalLoss, total: totalReceived + totalLoss };
    }, [lines, transfer.items]);

    function submit(e: FormEvent) {
        e.preventDefault();
        setSubmitting(true);
        router.post(
            route('inventory.transfers.receive', transfer.id),
            {
                items: Object.values(lines).map((l) => ({
                    id: l.id,
                    qty_received: parseFloat(l.qty_received) || 0,
                    variance_notes: l.variance_notes.trim() || null,
                })),
                receive_notes: receiveNotes.trim() || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success('Transfer diterima');
                    router.reload();
                },
                onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold">
                        Transfer {transfer.transfer_no}
                    </h2>
                    <Link href={route('inventory.transfers.index')}
                        className="text-sm text-sky-700 hover:underline">
                        ← kembali ke daftar
                    </Link>
                </div>
            }
        >
            <Head title={`Transfer ${transfer.transfer_no}`} />

            <div className="mx-auto max-w-5xl space-y-4 p-4">
                {/* Header info */}
                <Card>
                    <CardContent className="grid grid-cols-1 gap-3 p-4 md:grid-cols-2">
                        <div>
                            <div className="text-xs text-muted-foreground">No Transfer</div>
                            <div className="font-mono font-semibold">{transfer.transfer_no}</div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">Status</div>
                            <Badge variant={STATUS_VARIANT[transfer.status] ?? 'muted'}>
                                {STATUS_LABEL[transfer.status] ?? transfer.status}
                            </Badge>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">Asal</div>
                            <div className="font-medium">
                                {transfer.source_warehouse?.name}{' '}
                                <span className="text-xs text-muted-foreground">
                                    ({transfer.source_warehouse?.code})
                                </span>
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">Tujuan</div>
                            <div className="font-medium">
                                {transfer.dest_warehouse?.name}{' '}
                                <span className="text-xs text-muted-foreground">
                                    ({transfer.dest_warehouse?.code})
                                </span>
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">Dikirim</div>
                            <div className="text-sm">
                                {formatDateID(transfer.shipped_at)} oleh {transfer.shipper?.name}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">Diterima</div>
                            <div className="text-sm">
                                {transfer.received_at
                                    ? `${formatDateID(transfer.received_at)} oleh ${transfer.receiver?.name ?? '—'}`
                                    : '(belum diterima)'}
                            </div>
                        </div>
                        {transfer.notes && (
                            <div className="md:col-span-2">
                                <div className="text-xs text-muted-foreground">Catatan Kirim</div>
                                <div className="text-sm">{transfer.notes}</div>
                            </div>
                        )}
                        {transfer.receive_notes && (
                            <div className="md:col-span-2">
                                <div className="text-xs text-muted-foreground">Catatan Penerimaan</div>
                                <div className="text-sm">{transfer.receive_notes}</div>
                            </div>
                        )}
                        {(transfer.journal_ship || transfer.journal_receive) && (
                            <div className="md:col-span-2 space-y-1 border-t pt-3 text-xs">
                                {transfer.journal_ship && (
                                    <div>
                                        Jurnal kirim:{' '}
                                        <span className="font-mono">{transfer.journal_ship.journal_no}</span>
                                    </div>
                                )}
                                {transfer.journal_receive && (
                                    <div>
                                        Jurnal terima:{' '}
                                        <span className="font-mono">{transfer.journal_receive.journal_no}</span>
                                    </div>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Items */}
                <Card>
                    <CardContent className="p-0">
                        <form onSubmit={submit}>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Produk</TableHead>
                                        <TableHead className="text-right">Qty Dikirim</TableHead>
                                        <TableHead className="text-right">HPP saat Kirim</TableHead>
                                        <TableHead className="text-right">
                                            {isInTransit ? 'Qty Diterima *' : 'Qty Diterima'}
                                        </TableHead>
                                        <TableHead className="text-right">Selisih</TableHead>
                                        <TableHead>Catatan Selisih</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transfer.items.map((it) => {
                                        const sent = parseFloat(it.qty_sent);
                                        const recv = parseFloat(lines[it.id]?.qty_received || '0');
                                        const validRecv = isNaN(recv) ? 0 : recv;
                                        const variance = sent - validRecv;
                                        const overReceive = validRecv > sent;

                                        return (
                                            <TableRow key={it.id}>
                                                <TableCell>
                                                    <div className="font-medium">{it.product?.name}</div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {it.product?.sku}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {formatQty(it.qty_sent)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {rupiah(it.cost_at_transfer)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {canReceive && isInTransit ? (
                                                        <Input type="number" step="0.01" min="0" max={sent}
                                                            value={lines[it.id]?.qty_received ?? ''}
                                                            onChange={(e) => updateLine(it.id, { qty_received: e.target.value })}
                                                            className={`w-28 text-right ${overReceive ? 'border-red-500' : ''}`} />
                                                    ) : (
                                                        <span className="font-mono">
                                                            {it.qty_received !== null ? formatQty(it.qty_received) : '—'}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right font-mono">
                                                    {variance > 0 ? (
                                                        <span className="text-amber-700">
                                                            −{formatQty(variance)}
                                                        </span>
                                                    ) : variance < 0 ? (
                                                        <span className="text-red-700">
                                                            +{formatQty(Math.abs(variance))} (lebih?)
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">0</span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {canReceive && isInTransit ? (
                                                        <Input
                                                            value={lines[it.id]?.variance_notes ?? ''}
                                                            onChange={(e) => updateLine(it.id, { variance_notes: e.target.value })}
                                                            placeholder="alasan selisih"
                                                            className="text-xs"
                                                        />
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">
                                                            {it.variance_notes ?? '—'}
                                                        </span>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>

                            {isInTransit && canReceive && (
                                <div className="border-t p-4 space-y-3">
                                    <Card>
                                        <CardContent className="space-y-1 p-3 text-sm">
                                            <div className="flex justify-between">
                                                <span>Nilai diterima → D 1201 Persediaan (tujuan)</span>
                                                <span className="font-mono">{rupiah(totals.totalReceived)}</span>
                                            </div>
                                            <div className="flex justify-between">
                                                <span>Kerugian transit → D 5100 HPP</span>
                                                <span className={`font-mono ${totals.totalLoss > 0 ? 'text-amber-700' : ''}`}>
                                                    {rupiah(totals.totalLoss)}
                                                </span>
                                            </div>
                                            <div className="flex justify-between border-t pt-1 font-semibold">
                                                <span>Total → C 1203 BDP (BDP net 0)</span>
                                                <span className="font-mono">{rupiah(totals.total)}</span>
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <div>
                                        <Label htmlFor="r-notes">Catatan Penerimaan (opsional)</Label>
                                        <Input id="r-notes" value={receiveNotes}
                                            onChange={(e) => setReceiveNotes(e.target.value)}
                                            maxLength={1000}
                                            placeholder="cth: barang sampai dgn kondisi baik" />
                                    </div>

                                    <div className="flex justify-end">
                                        <Button type="submit" disabled={submitting}>
                                            {submitting ? 'Menyimpan…' : 'Konfirmasi Penerimaan'}
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {isInTransit && ! canReceive && (
                                <div className="border-t p-4 text-sm text-muted-foreground">
                                    Anda tidak punya akses untuk menerima transfer ini
                                    (warehouse tujuan = {transfer.dest_warehouse?.name}).
                                </div>
                            )}
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
