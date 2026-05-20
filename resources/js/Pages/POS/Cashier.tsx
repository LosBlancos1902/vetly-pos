import { useState, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Card, CardContent } from '@/Components/ui/card';
import { Table } from '@/Components/ui/table';
import { rupiah } from '@/lib/utils';
import PaymentDialog from './PaymentDialog';

interface CartLine {
    product_id: number;
    unit_id: number;
    name: string;
    price: number;
    qty: number;
}

interface Warehouse {
    id: number;
    code: string;
    name: string;
}

export default function Cashier({ warehouses }: { warehouses: Warehouse[] }) {
    const [warehouseId, setWarehouseId] = useState<number>(warehouses[0]?.id ?? 0);
    const [cart, setCart] = useState<CartLine[]>([]);
    const [barcode, setBarcode] = useState('');
    const [payOpen, setPayOpen] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    const total = cart.reduce((s, l) => s + l.price * l.qty, 0);

    async function onScan(e: React.FormEvent) {
        e.preventDefault();
        if (!barcode.trim()) return;
        try {
            const { data } = await axios.get(
                route('pos.scan', { barcode: barcode.trim() }),
                { params: { warehouse_id: warehouseId } },
            );
            if (!data.found) {
                toast.error('Produk tidak ditemukan');
                return;
            }
            if (!data.stock.allowed) {
                toast.error(data.stock.message);
                return;
            }
            if (data.stock.requires_confirmation) {
                if (!confirm(data.stock.message + '\nLanjutkan dengan override?')) return;
            }
            const p = data.product;
            setCart((c) => {
                const i = c.findIndex((l) => l.product_id === p.id);
                if (i >= 0) {
                    const copy = [...c];
                    copy[i] = { ...copy[i], qty: copy[i].qty + 1 };
                    return copy;
                }
                return [
                    ...c,
                    {
                        product_id: p.id,
                        unit_id: p.base_unit_id,
                        name: p.name,
                        price: Number(p.price),
                        qty: 1,
                    },
                ];
            });
            setBarcode('');
            inputRef.current?.focus();
        } catch {
            toast.error('Gagal scan produk');
        }
    }

    function setQty(idx: number, qty: number) {
        setCart((c) => c.map((l, i) => (i === idx ? { ...l, qty: Math.max(0.0001, qty) } : l)));
    }

    function removeLine(idx: number) {
        setCart((c) => c.filter((_, i) => i !== idx));
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Kasir (POS)</h2>}>
            <Head title="Kasir" />

            <div className="mx-auto grid max-w-7xl gap-4 p-4 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <form onSubmit={onScan} className="mb-4 flex gap-2">
                        <select
                            value={warehouseId}
                            onChange={(e) => setWarehouseId(Number(e.target.value))}
                            className="min-h-touch rounded-md border border-input bg-background px-3 text-base"
                        >
                            {warehouses.map((w) => (
                                <option key={w.id} value={w.id}>
                                    {w.name}
                                </option>
                            ))}
                        </select>
                        <Input
                            ref={inputRef}
                            autoFocus
                            value={barcode}
                            onChange={(e) => setBarcode(e.target.value)}
                            placeholder="Scan / ketik barcode lalu Enter"
                        />
                        <Button type="submit">Tambah</Button>
                    </form>

                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <tbody>
                                    {cart.length === 0 && (
                                        <tr>
                                            <td className="p-6 text-center text-muted-foreground">
                                                Keranjang kosong — mulai scan produk.
                                            </td>
                                        </tr>
                                    )}
                                    {cart.map((l, i) => (
                                        <tr key={i} className="border-b">
                                            <td className="p-3">
                                                <div className="font-medium">{l.name}</div>
                                                <div className="text-sm text-muted-foreground">
                                                    {rupiah(l.price)}
                                                </div>
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    type="number"
                                                    step="0.0001"
                                                    className="w-24"
                                                    value={l.qty}
                                                    onChange={(e) =>
                                                        setQty(i, Number(e.target.value))
                                                    }
                                                />
                                            </td>
                                            <td className="p-3 text-right font-semibold">
                                                {rupiah(l.price * l.qty)}
                                            </td>
                                            <td className="p-3">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => removeLine(i)}
                                                >
                                                    ✕
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                <div>
                    <Card>
                        <CardContent className="space-y-4 p-6">
                            <div className="flex justify-between text-lg">
                                <span>Total</span>
                                <span className="font-bold">{rupiah(total)}</span>
                            </div>
                            <Button
                                size="lg"
                                className="w-full"
                                disabled={cart.length === 0}
                                onClick={() => setPayOpen(true)}
                            >
                                BAYAR
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <PaymentDialog
                open={payOpen}
                onClose={() => setPayOpen(false)}
                total={total}
                onSubmit={async (payments) => {
                    try {
                        const { data } = await axios.post(route('pos.sales.store'), {
                            warehouse_id: warehouseId,
                            items: cart.map((l) => ({
                                product_id: l.product_id,
                                unit_id: l.unit_id,
                                qty: l.qty,
                                price: l.price,
                                discount_amount: 0,
                            })),
                            payments,
                        });
                        toast.success(`Transaksi ${data.sale.invoice_no} berhasil`);
                        setCart([]);
                        setPayOpen(false);
                        // ESC/POS payload ready: data.escpos_payload_58mm / _80mm
                        // -> printReceipt() from '@/lib/bluetoothPrinter'
                    } catch {
                        toast.error('Gagal menyimpan transaksi');
                    }
                }}
            />
        </AuthenticatedLayout>
    );
}
