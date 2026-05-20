import { useState } from 'react';
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

type Method = 'cash' | 'qris' | 'transfer' | 'debit' | 'credit' | 'ewallet' | 'voucher';

interface Payment {
    method: Method;
    amount: number;
}

const METHODS: Method[] = ['cash', 'qris', 'transfer', 'debit', 'credit', 'ewallet', 'voucher'];

export default function PaymentDialog({
    open,
    onClose,
    total,
    onSubmit,
}: {
    open: boolean;
    onClose: () => void;
    total: number;
    onSubmit: (payments: Payment[]) => void;
}) {
    const [payments, setPayments] = useState<Payment[]>([{ method: 'cash', amount: total }]);

    const paid = payments.reduce((s, p) => s + Number(p.amount || 0), 0);
    const change = paid - total;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Pembayaran — {rupiah(total)}</DialogTitle>
                </DialogHeader>

                <div className="space-y-3">
                    {payments.map((p, i) => (
                        <div key={i} className="flex gap-2">
                            <select
                                value={p.method}
                                onChange={(e) =>
                                    setPayments((arr) =>
                                        arr.map((x, j) =>
                                            j === i ? { ...x, method: e.target.value as Method } : x,
                                        ),
                                    )
                                }
                                className="min-h-touch rounded-md border border-input bg-background px-3 text-base"
                            >
                                {METHODS.map((m) => (
                                    <option key={m} value={m}>
                                        {m.toUpperCase()}
                                    </option>
                                ))}
                            </select>
                            <Input
                                type="number"
                                value={p.amount}
                                onChange={(e) =>
                                    setPayments((arr) =>
                                        arr.map((x, j) =>
                                            j === i
                                                ? { ...x, amount: Number(e.target.value) }
                                                : x,
                                        ),
                                    )
                                }
                            />
                        </div>
                    ))}

                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            setPayments((a) => [...a, { method: 'cash', amount: 0 }])
                        }
                    >
                        + Split pembayaran
                    </Button>

                    <div className="flex justify-between border-t pt-3 text-lg">
                        <Label>Kembalian</Label>
                        <span className={change < 0 ? 'text-destructive' : 'font-bold'}>
                            {rupiah(change)}
                        </span>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        size="lg"
                        disabled={paid < total}
                        onClick={() => onSubmit(payments)}
                    >
                        Selesaikan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
