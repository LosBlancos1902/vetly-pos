import { useState, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Card, CardContent } from '@/Components/ui/card';
import { Table } from '@/Components/ui/table';
import { Badge } from '@/Components/ui/badge';
import { rupiah } from '@/lib/utils';
import PaymentDialog from './PaymentDialog';
import ProductPicker, { type PickerProduct } from './ProductPicker';

interface CartLine {
    product_id: number;
    unit_id: number;
    name: string;
    price: number;
    qty: number;
    type: string;
}

const SERVICE_TYPES = ['service', 'service_with_consumption'];

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
    const [pickerOpen, setPickerOpen] = useState(false);
    const [pickerQuery, setPickerQuery] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    const total = cart.reduce((s, l) => s + l.price * l.qty, 0);

    async function lookupAndAdd(query: string): Promise<'added' | 'notfound' | 'error'> {
        try {
            const { data } = await axios.get(
                route('pos.scan', { barcode: query }),
                { params: { warehouse_id: warehouseId } },
            );
            if (!data.stock.allowed) {
                toast.error(data.stock.message);
                return 'error';
            }
            if (data.stock.requires_confirmation) {
                if (!confirm(data.stock.message + '\nLanjutkan dengan override?')) return 'error';
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
                        type: p.type,
                    },
                ];
            });
            return 'added';
        } catch (err: unknown) {
            const status = axios.isAxiosError(err) ? err.response?.status : undefined;
            if (status === 404) return 'notfound';
            console.error('Gagal scan produk', err);
            toast.error('Gagal scan produk');
            return 'error';
        }
    }

    async function onScan(e: React.FormEvent) {
        e.preventDefault();
        const q = barcode.trim();
        if (!q) return;
        const result = await lookupAndAdd(q);
        setBarcode('');
        if (result === 'notfound') {
            // Not a known barcode/SKU — fall back to name search via the picker.
            setPickerQuery(q);
            setPickerOpen(true);
            return;
        }
        inputRef.current?.focus();
    }

    async function handlePick(p: PickerProduct) {
        // Picker results carry a real barcode/SKU; scan resolves it and runs
        // the stock guard. A 404 here would be a data race, not a typo.
        const result = await lookupAndAdd(p.barcode ?? p.sku);
        if (result === 'notfound') {
            toast.error('Produk tidak ditemukan');
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
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => {
                                setPickerQuery('');
                                setPickerOpen(true);
                            }}
                        >
                            Cari Produk
                        </Button>
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
                                    {cart.map((l, i) => {
                                        const isService = SERVICE_TYPES.includes(l.type);
                                        return (
                                        <tr key={i} className="border-b">
                                            <td className="p-3">
                                                <div className="flex items-center gap-2 font-medium">
                                                    {l.name}
                                                    {isService && (
                                                        <Badge variant="info" className="text-[10px]">
                                                            JASA
                                                        </Badge>
                                                    )}
                                                </div>
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
                                                    disabled={isService}
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
                                        );
                                    })}
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

            <ProductPicker
                open={pickerOpen}
                warehouseId={warehouseId}
                initialQuery={pickerQuery}
                onClose={() => setPickerOpen(false)}
                onPick={handlePick}
            />

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
