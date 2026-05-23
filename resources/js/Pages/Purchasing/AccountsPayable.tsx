import { useState, type FormEvent } from 'react';
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

type Status = 'open' | 'partially_paid' | 'paid' | 'void';

interface SupplierLite {
    id: number;
    code: string;
    name: string;
}
interface ApPaymentLite {
    id: number;
    payment_no: string;
    amount: string;
    payment_coa_code: string;
    paid_at: string;
    notes: string | null;
}
interface AP {
    id: number;
    ap_no: string;
    supplier_id: number;
    amount: string;
    paid_amount: string;
    due_date: string;
    status: Status;
    supplier?: SupplierLite;
    goods_receipt?: { id: number; gr_no: string; received_at: string };
    purchase_order?: { id: number; po_no: string };
    payments: ApPaymentLite[];
    journal?: { id: number; journal_no: string };
}

interface PaginatedAPs {
    data: AP[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface CoaLite {
    code: string;
    name: string;
}

interface Props {
    payables: PaginatedAPs;
    filters: { status?: Status; supplier_id?: number };
    cashCoas: CoaLite[];
}

const STATUS_LABEL: Record<Status, { label: string; variant: 'default' | 'info' | 'success' | 'destructive' | 'muted' }> = {
    open: { label: 'Open', variant: 'info' },
    partially_paid: { label: 'Partial', variant: 'default' },
    paid: { label: 'Lunas', variant: 'success' },
    void: { label: 'Void', variant: 'destructive' },
};

export default function AccountsPayable({ payables, filters, cashCoas }: Props) {
    const { auth } = usePage().props;
    const canPay = auth.permissions?.includes('purchasing.ap_pay') ?? false;

    const [payAp, setPayAp] = useState<AP | null>(null);
    const [detailAp, setDetailAp] = useState<AP | null>(null);
    const today = new Date().toISOString().slice(0, 10);
    const [form, setForm] = useState({
        amount: '',
        payment_coa_code: '1101',
        paid_at: today,
        notes: '',
    });

    function startPay(ap: AP) {
        const remaining = Number(ap.amount) - Number(ap.paid_amount);
        setForm({
            amount: String(remaining),
            payment_coa_code: '1101',
            paid_at: today,
            notes: '',
        });
        setPayAp(ap);
    }

    function doPay(e: FormEvent) {
        e.preventDefault();
        if (!payAp) return;
        router.post(
            route('purchasing.payables.pay', payAp.id),
            {
                amount: Number(form.amount),
                payment_coa_code: form.payment_coa_code,
                paid_at: form.paid_at,
                notes: form.notes || null,
            },
            {
                onSuccess: () => {
                    toast.success(`Pembayaran ${payAp.ap_no} dicatat`);
                    setPayAp(null);
                },
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal bayar'),
                preserveScroll: true,
            },
        );
    }

    function filterStatus(s: Status | '') {
        router.get(
            route('purchasing.payables.index'),
            s ? { status: s } : {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function remaining(ap: AP) {
        return Number(ap.amount) - Number(ap.paid_amount);
    }

    function isOverdue(ap: AP) {
        return ap.status !== 'paid' && ap.due_date < today;
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Hutang Supplier</h2>}>
            <Head title="Hutang Supplier" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex items-center gap-2">
                    <Label htmlFor="status-filter">Status:</Label>
                    <select
                        id="status-filter"
                        value={filters.status ?? ''}
                        onChange={(e) => filterStatus(e.target.value as Status | '')}
                        className="flex h-10 rounded-md border border-input bg-background px-3 text-sm"
                    >
                        <option value="">Semua</option>
                        <option value="open">Open</option>
                        <option value="partially_paid">Partial</option>
                        <option value="paid">Lunas</option>
                    </select>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>AP No</TableHead>
                                    <TableHead>Supplier</TableHead>
                                    <TableHead>PO / GR</TableHead>
                                    <TableHead>Jatuh Tempo</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                    <TableHead className="text-right">Sisa</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payables.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-center text-muted-foreground">
                                            Belum ada hutang.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {payables.data.map((ap) => {
                                    const meta = STATUS_LABEL[ap.status];
                                    return (
                                        <TableRow key={ap.id}>
                                            <TableCell className="font-mono text-xs">{ap.ap_no}</TableCell>
                                            <TableCell>{ap.supplier?.name ?? '-'}</TableCell>
                                            <TableCell className="font-mono text-xs">
                                                <div>{ap.purchase_order?.po_no ?? '-'}</div>
                                                <div className="text-muted-foreground">
                                                    {ap.goods_receipt?.gr_no ?? '-'}
                                                </div>
                                            </TableCell>
                                            <TableCell className={isOverdue(ap) ? 'text-destructive' : ''}>
                                                {ap.due_date}
                                                {isOverdue(ap) && (
                                                    <span className="ml-1 text-xs">(lewat)</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">{rupiah(ap.amount)}</TableCell>
                                            <TableCell className="text-right">
                                                {rupiah(remaining(ap))}
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant={meta.variant}>{meta.label}</Badge>
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <Button variant="ghost" size="sm" onClick={() => setDetailAp(ap)}>
                                                    Detail
                                                </Button>
                                                {canPay && ap.status !== 'paid' && ap.status !== 'void' && (
                                                    <Button variant="ghost" size="sm" onClick={() => startPay(ap)}>
                                                        Bayar
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

                {payables.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>
                            {payables.from}–{payables.to} dari {payables.total}
                        </div>
                        <div className="flex gap-1">
                            {payables.links.map((l, i) => (
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

            {/* Pay dialog */}
            <Dialog open={payAp !== null} onOpenChange={(o) => !o && setPayAp(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Bayar Hutang {payAp?.ap_no}</DialogTitle>
                    </DialogHeader>
                    {payAp && (
                        <form onSubmit={doPay} className="space-y-3">
                            <div className="rounded-md bg-muted p-3 text-sm">
                                <div>
                                    <span className="text-muted-foreground">Supplier:</span>{' '}
                                    {payAp.supplier?.name}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Total:</span>{' '}
                                    {rupiah(payAp.amount)}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Sisa:</span>{' '}
                                    <span className="font-semibold">
                                        {rupiah(remaining(payAp))}
                                    </span>
                                </div>
                            </div>
                            <div>
                                <Label htmlFor="pay-amount">Jumlah bayar</Label>
                                <Input
                                    id="pay-amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    max={remaining(payAp)}
                                    value={form.amount}
                                    onChange={(e) => setForm({ ...form, amount: e.target.value })}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="pay-coa">Sumber dana</Label>
                                <select
                                    id="pay-coa"
                                    value={form.payment_coa_code}
                                    onChange={(e) => setForm({ ...form, payment_coa_code: e.target.value })}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                    required
                                >
                                    {cashCoas.map((c) => (
                                        <option key={c.code} value={c.code}>
                                            {c.code} — {c.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="pay-date">Tanggal bayar</Label>
                                <Input
                                    id="pay-date"
                                    type="date"
                                    value={form.paid_at}
                                    onChange={(e) => setForm({ ...form, paid_at: e.target.value })}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="pay-notes">Catatan</Label>
                                <Input
                                    id="pay-notes"
                                    value={form.notes}
                                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="ghost" onClick={() => setPayAp(null)}>
                                    Batal
                                </Button>
                                <Button type="submit">Catat Pembayaran</Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>

            {/* Detail dialog */}
            <Dialog open={detailAp !== null} onOpenChange={(o) => !o && setDetailAp(null)}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{detailAp?.ap_no}</DialogTitle>
                    </DialogHeader>
                    {detailAp && (
                        <div className="space-y-3 text-sm">
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <span className="text-muted-foreground">Supplier:</span>{' '}
                                    {detailAp.supplier?.name}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">PO:</span>{' '}
                                    <span className="font-mono">{detailAp.purchase_order?.po_no ?? '-'}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">GR:</span>{' '}
                                    <span className="font-mono">{detailAp.goods_receipt?.gr_no ?? '-'}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Tgl Terima:</span>{' '}
                                    {detailAp.goods_receipt?.received_at ?? '-'}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Jatuh Tempo:</span>{' '}
                                    {detailAp.due_date}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Status:</span>{' '}
                                    <Badge variant={STATUS_LABEL[detailAp.status].variant}>
                                        {STATUS_LABEL[detailAp.status].label}
                                    </Badge>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Total:</span>{' '}
                                    {rupiah(detailAp.amount)}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Sudah Dibayar:</span>{' '}
                                    {rupiah(detailAp.paid_amount)}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Sisa:</span>{' '}
                                    <span className="font-semibold">{rupiah(remaining(detailAp))}</span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Jurnal Hutang:</span>{' '}
                                    <span className="font-mono">{detailAp.journal?.journal_no ?? '-'}</span>
                                </div>
                            </div>
                            <div>
                                <h3 className="mb-2 font-medium">Riwayat Pembayaran</h3>
                                {detailAp.payments.length === 0 ? (
                                    <div className="rounded-md border p-3 text-center text-muted-foreground">
                                        Belum ada pembayaran.
                                    </div>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>No</TableHead>
                                                <TableHead>Tanggal</TableHead>
                                                <TableHead>Sumber</TableHead>
                                                <TableHead className="text-right">Jumlah</TableHead>
                                                <TableHead>Catatan</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {detailAp.payments.map((p) => (
                                                <TableRow key={p.id}>
                                                    <TableCell className="font-mono text-xs">{p.payment_no}</TableCell>
                                                    <TableCell>{p.paid_at}</TableCell>
                                                    <TableCell className="font-mono text-xs">{p.payment_coa_code}</TableCell>
                                                    <TableCell className="text-right">{rupiah(p.amount)}</TableCell>
                                                    <TableCell>{p.notes ?? '-'}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
