import { useMemo, useState } from 'react';
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
import ProductSearchInput, { type SearchProduct } from './ProductSearchInput';

interface UnitOption {
    id: number;            // product_unit row id
    unit_id: number;       // FK master_units.id
    code: string;
    name: string;
    level: number;
    conversion_to_base: number;
    is_sale_unit: boolean;
    prices: Record<string, number>; // tier_id (stringified) → price
}

interface CartLine {
    product_id: number;
    unit_id: number;       // master_units.id (yg disubmit ke server)
    name: string;
    price: number;
    qty: number;
    type: string;
    units: UnitOption[];   // semua satuan yg tersedia untuk produk ini
}

const SERVICE_TYPES = ['service', 'service_with_consumption'];

interface Warehouse {
    id: number;
    code: string;
    name: string;
}

interface PriceTier {
    id: number;
    name: string;
    sort_order: number;
    is_default: boolean;
    is_active: boolean;
}

interface Props {
    warehouses: Warehouse[];
    tiers: PriceTier[];
}

/**
 * Lookup harga dari units[] di cart line untuk tier+unit tertentu.
 * Server sudah pre-resolve fallback F2, jadi prices map selalu lengkap.
 */
function priceFor(units: UnitOption[], unitId: number, tierId: number): number {
    const u = units.find((x) => x.unit_id === unitId);
    if (! u) return 0;
    return u.prices[String(tierId)] ?? u.prices[Object.keys(u.prices)[0]] ?? 0;
}

export default function Cashier({ warehouses, tiers }: Props) {
    const [warehouseId, setWarehouseId] = useState<number>(warehouses[0]?.id ?? 0);
    const defaultTier = useMemo(() => tiers.find((t) => t.is_default) ?? tiers[0], [tiers]);
    const [tierId, setTierId] = useState<number>(defaultTier?.id ?? 0);
    const [cart, setCart] = useState<CartLine[]>([]);
    const [payOpen, setPayOpen] = useState(false);

    const total = cart.reduce((s, l) => s + l.price * l.qty, 0);

    // Ganti tier global → semua cart line auto-re-price ke tier baru
    // untuk SATUAN yg sedang dipilih per line.
    function changeTier(newTierId: number) {
        setTierId(newTierId);
        setCart((c) => c.map((l) => ({
            ...l,
            price: priceFor(l.units, l.unit_id, newTierId),
        })));
    }

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
            const units: UnitOption[] = p.units ?? [];

            // Pilih satuan default: base unit (level=1). Kalau scan hit
            // barcode_per_unit yg spesifik level lain, sebaiknya pilih itu —
            // tapi response saat ini cuma sediakan base_unit_id, jadi default
            // ke base. Pakai fallback ke unit pertama kalau base tidak ada.
            const baseUnit = units.find((u) => u.unit_id === p.base_unit_id)
                ?? units[0];
            const chosenUnitId = baseUnit?.unit_id ?? p.base_unit_id;
            const resolvedPrice = baseUnit
                ? priceFor(units, chosenUnitId, tierId)
                : Number(p.price);

            setCart((c) => {
                // Merge by (product_id, unit_id) — boleh ada line terpisah kalau
                // user sengaja jual produk sama di unit beda.
                const i = c.findIndex((l) => l.product_id === p.id && l.unit_id === chosenUnitId);
                if (i >= 0) {
                    const copy = [...c];
                    copy[i] = { ...copy[i], qty: copy[i].qty + 1 };
                    return copy;
                }
                return [
                    ...c,
                    {
                        product_id: p.id,
                        unit_id: chosenUnitId,
                        name: p.name,
                        price: resolvedPrice,
                        qty: 1,
                        type: p.type,
                        units,
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

    async function addSearchResult(p: SearchProduct) {
        const result = await lookupAndAdd(p.barcode ?? p.sku);
        if (result === 'notfound') {
            toast.error('Produk tidak ditemukan');
        }
    }

    function setQty(idx: number, qty: number) {
        setCart((c) => c.map((l, i) => (i === idx ? { ...l, qty: Math.max(0.0001, qty) } : l)));
    }

    function changeLineUnit(idx: number, newUnitId: number) {
        setCart((c) => c.map((l, i) => {
            if (i !== idx) return l;
            return { ...l, unit_id: newUnitId, price: priceFor(l.units, newUnitId, tierId) };
        }));
    }

    // Override harga per-line (diskon nego per barang). Total auto-recompute.
    function setPrice(idx: number, price: number) {
        setCart((c) => c.map((l, i) => (i === idx ? { ...l, price: Math.max(0, price) } : l)));
    }

    function removeLine(idx: number) {
        setCart((c) => c.filter((_, i) => i !== idx));
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Kasir (POS)</h2>}>
            <Head title="Kasir" />

            <div className="mx-auto grid max-w-7xl gap-4 p-4 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    <div className="mb-4 flex flex-wrap gap-2">
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
                        {tiers.length > 1 && (
                            <select
                                value={tierId}
                                onChange={(e) => changeTier(Number(e.target.value))}
                                className="min-h-touch rounded-md border border-input bg-background px-3 text-base"
                                title="Tier harga"
                            >
                                {tiers.filter((t) => t.is_active).map((t) => (
                                    <option key={t.id} value={t.id}>
                                        Tier: {t.name}{t.is_default ? ' (default)' : ''}
                                    </option>
                                ))}
                            </select>
                        )}
                        <ProductSearchInput
                            warehouseId={warehouseId}
                            onSelectProduct={addSearchResult}
                            onScanSubmit={lookupAndAdd}
                        />
                    </div>

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
                                        const hasMultiUnit = l.units.length > 1;
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
                                                <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                                    {hasMultiUnit ? (
                                                        <select
                                                            value={l.unit_id}
                                                            onChange={(e) => changeLineUnit(i, Number(e.target.value))}
                                                            className="h-8 rounded-md border border-input bg-background px-2 text-xs"
                                                        >
                                                            {l.units.map((u) => (
                                                                <option key={u.unit_id} value={u.unit_id}>
                                                                    {u.code}{u.level === 1 ? ' (base)' : ` × ${u.conversion_to_base}`}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    ) : (
                                                        <span>{l.units[0]?.code ?? ''}</span>
                                                    )}
                                                    <span>·</span>
                                                    <Input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        value={l.price}
                                                        onChange={(e) => setPrice(i, Number(e.target.value))}
                                                        className="h-8 w-28 text-right text-xs"
                                                        title="Harga per unit (boleh override)"
                                                        disabled={isService}
                                                    />
                                                </div>
                                            </td>
                                            <td className="p-3">
                                                <Input
                                                    type="number"
                                                    step="0.01"
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
                        <CardContent className="space-y-3 p-6">
                            <div className="space-y-1 text-sm text-muted-foreground">
                                <div className="flex justify-between">
                                    <span>Subtotal</span>
                                    <span>{rupiah(total)}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                    <span>{cart.length} item · {cart.reduce((s, l) => s + l.qty, 0).toFixed(2)} qty</span>
                                </div>
                            </div>
                            <div className="flex justify-between border-t pt-3 text-lg">
                                <span className="font-semibold">Total</span>
                                <span className="text-2xl font-bold">{rupiah(total)}</span>
                            </div>
                            <Button
                                size="lg"
                                className="w-full"
                                disabled={cart.length === 0}
                                onClick={() => setPayOpen(true)}
                            >
                                BAYAR · {rupiah(total)}
                            </Button>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <PaymentDialog
                open={payOpen}
                onClose={() => setPayOpen(false)}
                total={total}
                onSubmit={async (payment) => {
                    try {
                        const { data } = await axios.post(route('pos.sales.store'), {
                            warehouse_id: warehouseId,
                            price_tier_id: tierId || null,
                            items: cart.map((l) => ({
                                product_id: l.product_id,
                                unit_id: l.unit_id,
                                qty: l.qty,
                                price: l.price,
                                discount_amount: 0,
                            })),
                            payment_method: payment.payment_method,
                            amount_paid: payment.amount_paid,
                        });
                        toast.success(
                            `Transaksi ${data.sale.invoice_no} berhasil`
                            + (Number(data.sale.change_amount) > 0
                                ? ` · kembalian ${rupiah(Number(data.sale.change_amount))}`
                                : ''),
                        );
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
