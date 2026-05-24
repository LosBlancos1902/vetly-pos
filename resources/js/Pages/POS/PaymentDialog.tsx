import { useEffect, useMemo, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogFooter,
} from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { rupiah } from '@/lib/utils';

export type PaymentMethod = 'cash' | 'transfer' | 'qris';

export interface PaymentPayload {
    payment_method: PaymentMethod;
    amount_paid: number;
}

const METHODS: { value: PaymentMethod; label: string; description: string }[] = [
    { value: 'cash', label: 'CASH', description: 'Tunai · kembalian otomatis' },
    { value: 'transfer', label: 'TRANSFER', description: 'Bank · nominal = total' },
    { value: 'qris', label: 'QRIS', description: 'Scan QR · nominal = total' },
];

export default function PaymentDialog({
    open,
    onClose,
    total,
    onSubmit,
}: {
    open: boolean;
    onClose: () => void;
    total: number;
    onSubmit: (payload: PaymentPayload) => void | Promise<void>;
}) {
    const [method, setMethod] = useState<PaymentMethod>('cash');
    const [amountPaid, setAmountPaid] = useState<string>('');
    const [submitting, setSubmitting] = useState(false);

    // Reset state setiap kali dialog dibuka — jangan carry-over dari sale sebelumnya.
    useEffect(() => {
        if (open) {
            setMethod('cash');
            setAmountPaid('');
            setSubmitting(false);
        }
    }, [open]);

    const paidNum = Number(amountPaid) || 0;

    // Kembalian:
    // - cash: paid - total (≥ 0 kalau valid)
    // - transfer/qris: selalu 0 (paid = total exact)
    const change = useMemo(() => {
        if (method === 'cash') return Math.max(0, paidNum - total);

        return 0;
    }, [method, paidNum, total]);

    const isValid = useMemo(() => {
        if (method === 'cash') return paidNum + 0.001 >= total;

        return true; // transfer/qris auto-set paid = total saat submit
    }, [method, paidNum, total]);

    async function handleSubmit() {
        if (submitting) return;
        if (! isValid) return;

        setSubmitting(true);
        // Untuk non-cash: amount_paid = total (UI tidak biarkan user ketik).
        const finalPaid = method === 'cash' ? paidNum : total;
        try {
            await onSubmit({ payment_method: method, amount_paid: finalPaid });
        } finally {
            setSubmitting(false);
        }
    }

    function quickFill(amount: number) {
        setAmountPaid(String(amount));
    }

    // Quick-fill suggestions: total + pecahan umum di atasnya.
    // SENGAJA bukan rekomendasi pecahan optimal (per spec) — cuma helper.
    const quickAmounts = useMemo(() => {
        const opts = new Set<number>();
        opts.add(total);
        const round10 = Math.ceil(total / 10000) * 10000;
        const round50 = Math.ceil(total / 50000) * 50000;
        const round100 = Math.ceil(total / 100000) * 100000;
        if (round10 > total) opts.add(round10);
        if (round50 > total) opts.add(round50);
        if (round100 > total) opts.add(round100);

        return Array.from(opts).slice(0, 4);
    }, [total]);

    return (
        <Dialog open={open} onOpenChange={(o) => ! submitting && ! o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Pembayaran</DialogTitle>
                </DialogHeader>

                <div className="space-y-4">
                    {/* Total badge */}
                    <div className="flex items-center justify-between rounded-md bg-muted/50 p-3">
                        <span className="text-sm text-muted-foreground">Total tagihan</span>
                        <span className="text-2xl font-bold">{rupiah(total)}</span>
                    </div>

                    {/* Method selector */}
                    <div>
                        <Label className="mb-2 block">Metode Pembayaran</Label>
                        <div className="grid grid-cols-3 gap-2">
                            {METHODS.map((m) => (
                                <button
                                    type="button"
                                    key={m.value}
                                    onClick={() => setMethod(m.value)}
                                    className={`rounded-md border p-3 text-left transition-colors ${
                                        method === m.value
                                            ? 'border-primary bg-primary/10 ring-2 ring-primary'
                                            : 'border-input hover:bg-muted/50'
                                    }`}
                                >
                                    <div className="text-sm font-semibold">{m.label}</div>
                                    <div className="text-[10px] text-muted-foreground">{m.description}</div>
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* Cash: uang diterima + kembalian */}
                    {method === 'cash' && (
                        <>
                            <div>
                                <Label htmlFor="amount-paid">Uang Diterima</Label>
                                <Input
                                    id="amount-paid"
                                    type="number"
                                    step="0.01"
                                    min={total}
                                    autoFocus
                                    value={amountPaid}
                                    onChange={(e) => setAmountPaid(e.target.value)}
                                    placeholder={String(total)}
                                    className="text-right text-lg font-semibold"
                                />
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {quickAmounts.map((a) => (
                                        <Button
                                            key={a}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => quickFill(a)}
                                        >
                                            {rupiah(a)}
                                        </Button>
                                    ))}
                                </div>
                            </div>

                            <div className="flex items-center justify-between border-t pt-3">
                                <Label className="text-sm">Kembalian</Label>
                                <span className={`text-xl font-bold ${
                                    paidNum > 0 && paidNum < total ? 'text-destructive' : ''
                                }`}>
                                    {paidNum > 0 && paidNum < total
                                        ? `kurang ${rupiah(total - paidNum)}`
                                        : rupiah(change)}
                                </span>
                            </div>
                        </>
                    )}

                    {/* Transfer / QRIS */}
                    {method !== 'cash' && (
                        <div className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                            Nominal otomatis = <strong>{rupiah(total)}</strong>. Konfirmasi setelah customer
                            menyelesaikan {method === 'qris' ? 'scan QR' : 'transfer'}.
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="ghost" onClick={onClose} disabled={submitting}>
                        Batal
                    </Button>
                    <Button
                        size="lg"
                        disabled={! isValid || submitting}
                        onClick={handleSubmit}
                    >
                        {submitting ? 'Memproses…' : 'Selesaikan Transaksi'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
