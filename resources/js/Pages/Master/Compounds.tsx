import { useMemo, useState, type FormEvent } from 'react';
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
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';

interface MasterUnitLite {
    id: number;
    code: string;
    name: string;
}
interface ProductUnitLite {
    id: number;
    product_id: number;
    unit_id: number;
    unit: MasterUnitLite;
}
interface ProductLite {
    id: number;
    sku: string;
    name: string;
    type?: string;
    base_unit_id: number;
    units: ProductUnitLite[];
}
interface ComponentRow {
    id?: number;
    component_product_id: number;
    qty: string;
    unit_id: number;
    component?: { id: number; sku: string; name: string };
    unit?: MasterUnitLite;
}
interface Recipe {
    id: number;
    product_id: number;
    name: string;
    yield_qty: string;
    yield_unit_id: number;
    racik_fee: string;
    markup_percent: string;
    notes: string | null;
    is_active: boolean;
    product: { id: number; sku: string; name: string };
    yield_unit: MasterUnitLite;
    components: ComponentRow[];
}

interface Props {
    recipes: Recipe[];
    yieldProducts: ProductLite[];
    componentProducts: ProductLite[];
}

interface FormState {
    id?: number;
    product_id: string;
    name: string;
    yield_qty: string;
    yield_unit_id: string;
    racik_fee: string;
    markup_percent: string;
    notes: string;
    is_active: boolean;
    components: Array<{
        component_product_id: string;
        qty: string;
        unit_id: string;
    }>;
}

const EMPTY_FORM: FormState = {
    product_id: '',
    name: '',
    yield_qty: '',
    yield_unit_id: '',
    racik_fee: '0',
    markup_percent: '0',
    notes: '',
    is_active: true,
    components: [{ component_product_id: '', qty: '', unit_id: '' }],
};

export default function Compounds({ recipes, yieldProducts, componentProducts }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const isEdit = form.id !== undefined;

    const yieldProductsById = useMemo(
        () => new Map(yieldProducts.map((p) => [p.id, p])),
        [yieldProducts],
    );
    const componentProductsById = useMemo(
        () => new Map(componentProducts.map((p) => [p.id, p])),
        [componentProducts],
    );

    const selectedYieldProduct = form.product_id
        ? yieldProductsById.get(Number(form.product_id))
        : undefined;

    function startCreate() {
        setForm(EMPTY_FORM);
        setOpen(true);
    }

    function startEdit(r: Recipe) {
        setForm({
            id: r.id,
            product_id: String(r.product_id),
            name: r.name,
            yield_qty: String(r.yield_qty),
            yield_unit_id: String(r.yield_unit_id),
            racik_fee: String(r.racik_fee),
            markup_percent: String(r.markup_percent),
            notes: r.notes ?? '',
            is_active: r.is_active,
            components: r.components.map((c) => ({
                component_product_id: String(c.component_product_id),
                qty: String(c.qty),
                unit_id: String(c.unit_id),
            })),
        });
        setOpen(true);
    }

    function addRow() {
        setForm({
            ...form,
            components: [...form.components, { component_product_id: '', qty: '', unit_id: '' }],
        });
    }

    function removeRow(idx: number) {
        if (form.components.length === 1) return;
        setForm({
            ...form,
            components: form.components.filter((_, i) => i !== idx),
        });
    }

    function updateRow(idx: number, patch: Partial<FormState['components'][number]>) {
        setForm({
            ...form,
            components: form.components.map((row, i) => (i === idx ? { ...row, ...patch } : row)),
        });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const payload = {
            product_id: Number(form.product_id),
            name: form.name,
            yield_qty: Number(form.yield_qty),
            yield_unit_id: Number(form.yield_unit_id),
            racik_fee: Number(form.racik_fee || 0),
            markup_percent: Number(form.markup_percent || 0),
            notes: form.notes || null,
            is_active: form.is_active,
            components: form.components.map((c) => ({
                component_product_id: Number(c.component_product_id),
                qty: Number(c.qty),
                unit_id: Number(c.unit_id),
            })),
        };

        const opts = {
            onSuccess: () => {
                toast.success(isEdit ? 'Resep diperbarui' : 'Resep ditambahkan');
                setOpen(false);
            },
            onError: (errs: Record<string, string>) => {
                const first = Object.values(errs)[0];
                if (first) toast.error(first);
            },
            preserveScroll: true,
        };

        if (isEdit) {
            router.put(route('master.compounds.update', form.id), payload, opts);
        } else {
            router.post(route('master.compounds.store'), payload, opts);
        }
    }

    function remove(r: Recipe) {
        if (!confirm(`Hapus resep "${r.name}"?`)) return;
        router.delete(route('master.compounds.destroy', r.id), {
            onSuccess: () => toast.success(`Resep "${r.name}" dihapus`),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal menghapus'),
            preserveScroll: true,
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Resep Racikan</h2>}>
            <Head title="Resep Racikan" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <h3 className="text-lg font-medium">Daftar Resep ({recipes.length})</h3>
                    <Button onClick={startCreate} className="min-h-11">
                        + Tambah Resep
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Produk Hasil</TableHead>
                                    <TableHead>Yield</TableHead>
                                    <TableHead>Komponen</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {recipes.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                            Belum ada resep racikan.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {recipes.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell className="font-medium">{r.name}</TableCell>
                                        <TableCell>
                                            <div className="text-sm">{r.product.name}</div>
                                            <div className="text-xs text-muted-foreground">{r.product.sku}</div>
                                        </TableCell>
                                        <TableCell>
                                            {Number(r.yield_qty)} {r.yield_unit.code}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="muted">{r.components.length} item</Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={r.is_active ? 'success' : 'destructive'}>
                                                {r.is_active ? 'Aktif' : 'Nonaktif'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button variant="ghost" size="sm" onClick={() => startEdit(r)}>
                                                Edit
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive"
                                                onClick={() => remove(r)}
                                            >
                                                Hapus
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{isEdit ? 'Edit Resep' : 'Tambah Resep'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <Label htmlFor="c-product">Produk Hasil (compoundable)</Label>
                                <select
                                    id="c-product"
                                    value={form.product_id}
                                    onChange={(e) =>
                                        setForm({ ...form, product_id: e.target.value, yield_unit_id: '' })
                                    }
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                    required
                                >
                                    <option value="">— Pilih produk —</option>
                                    {yieldProducts.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name} ({p.sku})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="c-name">Nama Resep</Label>
                                <Input
                                    id="c-name"
                                    value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="c-yieldqty">Yield Qty</Label>
                                <Input
                                    id="c-yieldqty"
                                    type="number"
                                    step="0.0001"
                                    min="0.0001"
                                    value={form.yield_qty}
                                    onChange={(e) => setForm({ ...form, yield_qty: e.target.value })}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="c-yieldunit">Yield Unit</Label>
                                <select
                                    id="c-yieldunit"
                                    value={form.yield_unit_id}
                                    onChange={(e) => setForm({ ...form, yield_unit_id: e.target.value })}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                    required
                                    disabled={!selectedYieldProduct}
                                >
                                    <option value="">— Pilih unit —</option>
                                    {selectedYieldProduct?.units.map((u) => (
                                        <option key={u.id} value={u.unit_id}>
                                            {u.unit.code} — {u.unit.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="c-fee">Racik Fee (Rp)</Label>
                                <Input
                                    id="c-fee"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.racik_fee}
                                    onChange={(e) => setForm({ ...form, racik_fee: e.target.value })}
                                />
                            </div>
                            <div>
                                <Label htmlFor="c-markup">Markup (%)</Label>
                                <Input
                                    id="c-markup"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.markup_percent}
                                    onChange={(e) => setForm({ ...form, markup_percent: e.target.value })}
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Label htmlFor="c-notes">Catatan</Label>
                                <Input
                                    id="c-notes"
                                    value={form.notes}
                                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                />
                            </div>
                            <div className="flex items-center gap-2 md:col-span-2">
                                <input
                                    id="c-active"
                                    type="checkbox"
                                    checked={form.is_active}
                                    onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                                />
                                <Label htmlFor="c-active">Aktif</Label>
                            </div>
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>Komponen</Label>
                                <Button type="button" variant="outline" size="sm" onClick={addRow}>
                                    + Tambah Komponen
                                </Button>
                            </div>

                            <div className="space-y-2">
                                {form.components.map((row, idx) => {
                                    const product = row.component_product_id
                                        ? componentProductsById.get(Number(row.component_product_id))
                                        : undefined;
                                    return (
                                        <div
                                            key={idx}
                                            className="grid grid-cols-12 gap-2 rounded-md border p-2"
                                        >
                                            <div className="col-span-12 md:col-span-6">
                                                <select
                                                    value={row.component_product_id}
                                                    onChange={(e) =>
                                                        updateRow(idx, {
                                                            component_product_id: e.target.value,
                                                            unit_id: '',
                                                        })
                                                    }
                                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                                    required
                                                >
                                                    <option value="">— Pilih komponen —</option>
                                                    {componentProducts.map((p) => (
                                                        <option key={p.id} value={p.id}>
                                                            {p.name} ({p.sku})
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="col-span-5 md:col-span-2">
                                                <Input
                                                    type="number"
                                                    step="0.0001"
                                                    min="0.0001"
                                                    placeholder="Qty"
                                                    value={row.qty}
                                                    onChange={(e) =>
                                                        updateRow(idx, { qty: e.target.value })
                                                    }
                                                    required
                                                />
                                            </div>
                                            <div className="col-span-5 md:col-span-3">
                                                <select
                                                    value={row.unit_id}
                                                    onChange={(e) =>
                                                        updateRow(idx, { unit_id: e.target.value })
                                                    }
                                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                                    required
                                                    disabled={!product}
                                                >
                                                    <option value="">— Unit —</option>
                                                    {product?.units.map((u) => (
                                                        <option key={u.id} value={u.unit_id}>
                                                            {u.unit.code}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="col-span-2 md:col-span-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive"
                                                    onClick={() => removeRow(idx)}
                                                    disabled={form.components.length === 1}
                                                >
                                                    Hapus
                                                </Button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                Batal
                            </Button>
                            <Button type="submit">{isEdit ? 'Simpan' : 'Tambah'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
