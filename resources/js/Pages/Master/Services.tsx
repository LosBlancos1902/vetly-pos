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
import { rupiah } from '@/lib/utils';

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
    type: string;
    base_unit_id: number;
    units?: ProductUnitLite[];
}
interface BundleItem {
    id?: number;
    component_product_id: number;
    qty: string;
    unit_id: number;
    is_optional: boolean;
    component?: { id: number; sku: string; name: string };
    unit?: MasterUnitLite;
}
interface Bundle {
    id: number;
    product_id: number;
    name: string;
    service_fee: string;
    notes: string | null;
    is_active: boolean;
    product: { id: number; sku: string; name: string; type: string };
    items: BundleItem[];
}

interface Props {
    bundles: Bundle[];
    serviceProducts: ProductLite[];
    componentProducts: ProductLite[];
}

interface FormState {
    id?: number;
    product_id: string;
    name: string;
    service_fee: string;
    notes: string;
    is_active: boolean;
    items: Array<{
        component_product_id: string;
        qty: string;
        unit_id: string;
        is_optional: boolean;
    }>;
}

const EMPTY_ITEM = { component_product_id: '', qty: '', unit_id: '', is_optional: false };

const EMPTY_FORM: FormState = {
    product_id: '',
    name: '',
    service_fee: '',
    notes: '',
    is_active: true,
    items: [],
};

export default function Services({ bundles, serviceProducts, componentProducts }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const isEdit = form.id !== undefined;

    const serviceById = useMemo(
        () => new Map(serviceProducts.map((p) => [p.id, p])),
        [serviceProducts],
    );
    const componentById = useMemo(
        () => new Map(componentProducts.map((p) => [p.id, p])),
        [componentProducts],
    );

    const selectedService = form.product_id
        ? serviceById.get(Number(form.product_id))
        : undefined;
    const needsConsumption = selectedService?.type === 'service_with_consumption';

    function startCreate() {
        setForm(EMPTY_FORM);
        setOpen(true);
    }

    function startEdit(b: Bundle) {
        setForm({
            id: b.id,
            product_id: String(b.product_id),
            name: b.name,
            service_fee: String(b.service_fee),
            notes: b.notes ?? '',
            is_active: b.is_active,
            items: b.items.map((it) => ({
                component_product_id: String(it.component_product_id),
                qty: String(it.qty),
                unit_id: String(it.unit_id),
                is_optional: it.is_optional,
            })),
        });
        setOpen(true);
    }

    function addRow() {
        setForm({ ...form, items: [...form.items, { ...EMPTY_ITEM }] });
    }

    function removeRow(idx: number) {
        setForm({ ...form, items: form.items.filter((_, i) => i !== idx) });
    }

    function updateRow(idx: number, patch: Partial<FormState['items'][number]>) {
        setForm({
            ...form,
            items: form.items.map((row, i) => (i === idx ? { ...row, ...patch } : row)),
        });
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const payload = {
            product_id: Number(form.product_id),
            name: form.name,
            service_fee: Number(form.service_fee),
            notes: form.notes || null,
            is_active: form.is_active,
            items: form.items.map((it) => ({
                component_product_id: Number(it.component_product_id),
                qty: Number(it.qty),
                unit_id: Number(it.unit_id),
                is_optional: it.is_optional,
            })),
        };

        const opts = {
            onSuccess: () => {
                toast.success(isEdit ? 'Bundle diperbarui' : 'Bundle ditambahkan');
                setOpen(false);
            },
            onError: (errs: Record<string, string>) => {
                const first = Object.values(errs)[0];
                if (first) toast.error(first);
            },
            preserveScroll: true,
        };

        if (isEdit) {
            router.put(route('master.services.update', form.id), payload, opts);
        } else {
            router.post(route('master.services.store'), payload, opts);
        }
    }

    function remove(b: Bundle) {
        if (!confirm(`Hapus bundle "${b.name}"?`)) return;
        router.delete(route('master.services.destroy', b.id), {
            onSuccess: () => toast.success(`Bundle "${b.name}" dihapus`),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal menghapus'),
            preserveScroll: true,
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Service Bundle</h2>}>
            <Head title="Service Bundle" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <h3 className="text-lg font-medium">Daftar Bundle ({bundles.length})</h3>
                    <Button onClick={startCreate} className="min-h-11">
                        + Tambah Bundle
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Produk Jasa</TableHead>
                                    <TableHead>Tipe</TableHead>
                                    <TableHead className="text-right">Service Fee</TableHead>
                                    <TableHead>Komponen</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {bundles.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground">
                                            Belum ada bundle.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {bundles.map((b) => (
                                    <TableRow key={b.id}>
                                        <TableCell className="font-medium">{b.name}</TableCell>
                                        <TableCell>
                                            <div className="text-sm">{b.product.name}</div>
                                            <div className="text-xs text-muted-foreground">{b.product.sku}</div>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={b.product.type === 'service_with_consumption' ? 'info' : 'muted'}>
                                                {b.product.type === 'service_with_consumption' ? 'Jasa + Bahan' : 'Jasa'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">{rupiah(b.service_fee)}</TableCell>
                                        <TableCell>
                                            <Badge variant="muted">{b.items.length} item</Badge>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={b.is_active ? 'success' : 'destructive'}>
                                                {b.is_active ? 'Aktif' : 'Nonaktif'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button variant="ghost" size="sm" onClick={() => startEdit(b)}>
                                                Edit
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive"
                                                onClick={() => remove(b)}
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
                        <DialogTitle>{isEdit ? 'Edit Bundle' : 'Tambah Bundle'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <Label htmlFor="s-product">Produk Jasa</Label>
                                <select
                                    id="s-product"
                                    value={form.product_id}
                                    onChange={(e) => setForm({ ...form, product_id: e.target.value })}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                    required
                                >
                                    <option value="">— Pilih produk jasa —</option>
                                    {serviceProducts.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.name} ({p.sku}){' '}
                                            {p.type === 'service_with_consumption' ? '· Jasa + Bahan' : '· Jasa'}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="s-name">Nama Bundle</Label>
                                <Input
                                    id="s-name"
                                    value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="s-fee">Service Fee (Rp)</Label>
                                <Input
                                    id="s-fee"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.service_fee}
                                    onChange={(e) => setForm({ ...form, service_fee: e.target.value })}
                                    required
                                />
                            </div>
                            <div className="flex items-center gap-2">
                                <input
                                    id="s-active"
                                    type="checkbox"
                                    checked={form.is_active}
                                    onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                                />
                                <Label htmlFor="s-active">Aktif</Label>
                            </div>
                            <div className="md:col-span-2">
                                <Label htmlFor="s-notes">Catatan</Label>
                                <Input
                                    id="s-notes"
                                    value={form.notes}
                                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <Label>
                                    Komponen{' '}
                                    {needsConsumption && (
                                        <span className="text-xs text-destructive">
                                            (wajib ≥1 untuk jenis "Jasa + Bahan")
                                        </span>
                                    )}
                                </Label>
                                <Button type="button" variant="outline" size="sm" onClick={addRow}>
                                    + Tambah Komponen
                                </Button>
                            </div>

                            <div className="space-y-2">
                                {form.items.length === 0 && (
                                    <div className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">
                                        Belum ada komponen.
                                    </div>
                                )}
                                {form.items.map((row, idx) => {
                                    const product = row.component_product_id
                                        ? componentById.get(Number(row.component_product_id))
                                        : undefined;
                                    return (
                                        <div
                                            key={idx}
                                            className="grid grid-cols-12 gap-2 rounded-md border p-2"
                                        >
                                            <div className="col-span-12 md:col-span-5">
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
                                            <div className="col-span-4 md:col-span-2">
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
                                            <div className="col-span-4 md:col-span-2">
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
                                                    {product?.units?.map((u) => (
                                                        <option key={u.id} value={u.unit_id}>
                                                            {u.unit.code}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="col-span-2 md:col-span-2 flex items-center gap-1">
                                                <input
                                                    id={`s-opt-${idx}`}
                                                    type="checkbox"
                                                    checked={row.is_optional}
                                                    onChange={(e) =>
                                                        updateRow(idx, { is_optional: e.target.checked })
                                                    }
                                                />
                                                <Label htmlFor={`s-opt-${idx}`} className="text-xs">
                                                    Opsional
                                                </Label>
                                            </div>
                                            <div className="col-span-2 md:col-span-1">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive"
                                                    onClick={() => removeRow(idx)}
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
