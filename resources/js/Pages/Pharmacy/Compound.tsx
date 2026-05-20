import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
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
import { rupiah } from '@/lib/utils';

interface MasterUnitLite {
    id: number;
    code: string;
    name: string;
}
interface Recipe {
    id: number;
    name: string;
    yield_qty: string;
    yield_unit_id: number;
    racik_fee: string;
    markup_percent: string;
    product: { id: number; sku: string; name: string };
    yield_unit: MasterUnitLite;
    components: Array<{
        id: number;
        component_product_id: number;
        qty: string;
        unit_id: number;
        component: { id: number; sku: string; name: string };
        unit: MasterUnitLite;
    }>;
}
interface Warehouse {
    id: number;
    code: string;
    name: string;
}
interface PreviewComponent {
    product_id: number;
    cost_avg: string;
    required_base: string;
    available_base: string;
    is_short: boolean;
    line_cost_per_batch: string;
}
interface Preview {
    recipe: { id: number; name: string; yield_qty: string; yield_unit: string };
    batch: number;
    cost_total_per_batch: string;
    cost_total: string;
    yield_base_total: string;
    cost_per_yield_base: string;
    suggested_price: string;
    components: PreviewComponent[];
    has_shortage: boolean;
}

interface Props {
    recipes: Recipe[];
    warehouses: Warehouse[];
    defaultWarehouseId: number | null;
}

export default function CompoundPage({ recipes, warehouses, defaultWarehouseId }: Props) {
    const [recipeId, setRecipeId] = useState<string>('');
    const [warehouseId, setWarehouseId] = useState<string>(
        defaultWarehouseId ? String(defaultWarehouseId) : '',
    );
    const [qtyBatch, setQtyBatch] = useState<string>('1');
    const [mode, setMode] = useState<'to_stock' | 'direct_sale'>('to_stock');
    const [overridePrice, setOverridePrice] = useState<string>('');
    const [notes, setNotes] = useState<string>('');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [executing, setExecuting] = useState(false);

    const componentNameById = useMemo(() => {
        const map = new Map<number, string>();
        recipes.forEach((r) =>
            r.components.forEach((c) =>
                map.set(c.component_product_id, `${c.component.name} (${c.component.sku})`),
            ),
        );
        return map;
    }, [recipes]);

    const selectedRecipe = recipeId ? recipes.find((r) => r.id === Number(recipeId)) : undefined;

    // Auto-fetch preview when inputs are complete.
    useEffect(() => {
        if (!recipeId || !warehouseId || !qtyBatch || Number(qtyBatch) < 1) {
            setPreview(null);
            return;
        }
        const ctrl = new AbortController();
        setPreviewLoading(true);
        fetch(
            route('pharmacy.compound.preview') +
                `?recipe_id=${recipeId}&warehouse_id=${warehouseId}&qty_batch=${qtyBatch}`,
            { signal: ctrl.signal, headers: { Accept: 'application/json' } },
        )
            .then((r) => (r.ok ? r.json() : Promise.reject(r)))
            .then((data: Preview) => setPreview(data))
            .catch((err) => {
                if (err?.name !== 'AbortError') {
                    setPreview(null);
                }
            })
            .finally(() => setPreviewLoading(false));
        return () => ctrl.abort();
    }, [recipeId, warehouseId, qtyBatch]);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (preview?.has_shortage) {
            if (!confirm('Stok komponen tidak cukup — eksekusi tetap akan gagal di server. Lanjutkan?')) {
                return;
            }
        }
        setExecuting(true);
        router.post(
            route('pharmacy.compound.execute'),
            {
                recipe_id: Number(recipeId),
                warehouse_id: Number(warehouseId),
                qty_batch: Number(qtyBatch),
                mode,
                override_price: overridePrice ? Number(overridePrice) : null,
                notes: notes || null,
            },
            {
                onSuccess: () => {
                    toast.success('Racikan berhasil dieksekusi');
                    setOverridePrice('');
                    setNotes('');
                    // Refresh preview to reflect new stock levels.
                    setRecipeId((id) => id);
                },
                onError: (errs) => {
                    const first = Object.values(errs)[0];
                    if (first) toast.error(first);
                },
                onFinish: () => setExecuting(false),
                preserveScroll: true,
            },
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Apotek — Eksekusi Racikan</h2>}>
            <Head title="Racikan" />

            <div className="mx-auto max-w-5xl space-y-4 p-4">
                <Card>
                    <CardContent className="space-y-4 p-4">
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="rx-recipe">Resep</Label>
                                    <select
                                        id="rx-recipe"
                                        value={recipeId}
                                        onChange={(e) => setRecipeId(e.target.value)}
                                        className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                        required
                                    >
                                        <option value="">— Pilih resep —</option>
                                        {recipes.map((r) => (
                                            <option key={r.id} value={r.id}>
                                                {r.name} → {r.product.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <Label htmlFor="rx-warehouse">Warehouse</Label>
                                    <select
                                        id="rx-warehouse"
                                        value={warehouseId}
                                        onChange={(e) => setWarehouseId(e.target.value)}
                                        className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                        required
                                    >
                                        <option value="">— Pilih warehouse —</option>
                                        {warehouses.map((w) => (
                                            <option key={w.id} value={w.id}>
                                                {w.name} ({w.code})
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <Label htmlFor="rx-qty">Qty Batch</Label>
                                    <Input
                                        id="rx-qty"
                                        type="number"
                                        min="1"
                                        step="1"
                                        value={qtyBatch}
                                        onChange={(e) => setQtyBatch(e.target.value)}
                                        required
                                    />
                                </div>
                                <div>
                                    <Label>Mode</Label>
                                    <div className="flex h-11 items-center gap-4">
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                name="mode"
                                                value="to_stock"
                                                checked={mode === 'to_stock'}
                                                onChange={() => setMode('to_stock')}
                                            />
                                            <span className="text-sm">To-stock (racik dulu, jual nanti)</span>
                                        </label>
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="radio"
                                                name="mode"
                                                value="direct_sale"
                                                checked={mode === 'direct_sale'}
                                                onChange={() => setMode('direct_sale')}
                                            />
                                            <span className="text-sm">Direct-sale (langsung jual)</span>
                                        </label>
                                    </div>
                                </div>
                                <div>
                                    <Label htmlFor="rx-price">Override Harga (opsional)</Label>
                                    <Input
                                        id="rx-price"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        placeholder={preview ? `Suggested: ${preview.suggested_price}` : ''}
                                        value={overridePrice}
                                        onChange={(e) => setOverridePrice(e.target.value)}
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="rx-notes">Catatan</Label>
                                    <Input
                                        id="rx-notes"
                                        value={notes}
                                        onChange={(e) => setNotes(e.target.value)}
                                    />
                                </div>
                            </div>

                            <Button
                                type="submit"
                                className="w-full min-h-11"
                                disabled={!recipeId || !warehouseId || executing}
                            >
                                {executing ? 'Memproses…' : 'Eksekusi Racikan'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {selectedRecipe && (
                    <Card>
                        <CardContent className="space-y-3 p-4">
                            <div className="flex items-center justify-between">
                                <h3 className="font-semibold">Preview Cost & Stok</h3>
                                {previewLoading && (
                                    <span className="text-xs text-muted-foreground">memuat…</span>
                                )}
                                {preview?.has_shortage && (
                                    <Badge variant="destructive">Stok tidak cukup</Badge>
                                )}
                            </div>

                            {preview && (
                                <div className="grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
                                    <Stat label="Cost Total" value={rupiah(preview.cost_total)} />
                                    <Stat
                                        label="Yield Total"
                                        value={`${Number(preview.yield_base_total)} ${preview.recipe.yield_unit ?? ''}`}
                                    />
                                    <Stat
                                        label="Cost / Yield Unit"
                                        value={rupiah(preview.cost_per_yield_base)}
                                    />
                                    <Stat label="Harga Saran" value={rupiah(preview.suggested_price)} />
                                </div>
                            )}

                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Komponen</TableHead>
                                        <TableHead className="text-right">Butuh (base)</TableHead>
                                        <TableHead className="text-right">Tersedia (base)</TableHead>
                                        <TableHead className="text-right">Cost / Batch</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {preview?.components.map((c) => (
                                        <TableRow key={c.product_id}>
                                            <TableCell>{componentNameById.get(c.product_id) ?? `#${c.product_id}`}</TableCell>
                                            <TableCell className="text-right">{Number(c.required_base)}</TableCell>
                                            <TableCell className="text-right">{Number(c.available_base)}</TableCell>
                                            <TableCell className="text-right">{rupiah(c.line_cost_per_batch)}</TableCell>
                                            <TableCell>
                                                {c.is_short ? (
                                                    <Badge variant="destructive">Kurang</Badge>
                                                ) : (
                                                    <Badge variant="success">OK</Badge>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {!preview && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="text-center text-muted-foreground">
                                                Pilih resep + warehouse + qty untuk melihat preview.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-md border p-2">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="font-semibold">{value}</div>
        </div>
    );
}
