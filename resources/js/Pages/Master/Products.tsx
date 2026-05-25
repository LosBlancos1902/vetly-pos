import { useMemo, useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { formatQty, rupiah, inputMoney, inputQty } from '@/lib/utils';

// ── Types ────────────────────────────────────────────────────────────────

interface Category { id: number; name: string }
interface Brand { id: number; name: string }
interface MasterUnit { id: number; code: string; name: string }
interface PriceTier {
    id: number;
    name: string;
    sort_order: number;
    is_default: boolean;
    is_active: boolean;
}
interface ProductRow {
    id: number;
    sku: string;
    name: string;
    barcode: string | null;
    type: string;
    price: string;
    is_active: boolean;
    category?: { id: number; name: string };
    brand?: { id: number; name: string } | null;
    base_unit?: { id: number; code: string };
}
interface Paginated {
    data: ProductRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}
interface UnitDetail {
    id: number;
    unit_id: number;
    level: number;
    conversion_to_base: string;
    is_purchase_unit: boolean;
    is_sale_unit: boolean;
    barcode_per_unit: string | null;
    unit: MasterUnit;
    prices: Array<{ id: number; price_tier_id: number; price: string }>;
}
interface InventoryRow {
    id: number;
    qty: string;
    cost_avg: string;
    warehouse?: {
        id: number;
        code: string;
        name: string;
        warehouse_type: string;
        is_active: boolean;
    };
}
interface ProductDetail extends ProductRow {
    description: string | null;
    category_id: number;
    brand_id: number | null;
    base_unit_id: number;
    min_stock: string;
    units: UnitDetail[];
    inventories?: InventoryRow[];
}

interface Props {
    products: Paginated;
    categories: Category[];
    brands: Brand[];
    units: MasterUnit[];
    tiers: PriceTier[];
    filters: { search?: string };
}

// ── Form state ───────────────────────────────────────────────────────────

interface PriceCell {
    price_tier_id: number;
    price: string; // '' = kosong → fallback
}
interface UnitRow {
    key: string;             // local id untuk React key
    unit_id: number | '';
    level: number;
    conversion_to_base: string;
    barcode_per_unit: string;
    prices: PriceCell[];     // 1 cell per tier (sync dgn props.tiers)
}
interface FormState {
    id?: number;
    sku: string;
    name: string;
    barcode: string;
    description: string;
    category_id: string;
    brand_id: string;
    type: string;
    min_stock: string;
    is_active: boolean;
    units: UnitRow[];
}

const PRODUCT_TYPES = [
    { value: 'saleable_retail', label: 'Persediaan (Retail)' },
    { value: 'raw_material', label: 'Bahan Baku' },
    { value: 'compoundable_drug', label: 'Obat Racik' },
    { value: 'service', label: 'Jasa' },
    { value: 'service_with_consumption', label: 'Jasa + Konsumsi Bahan' },
];

const TYPE_LABEL: Record<string, string> = Object.fromEntries(
    PRODUCT_TYPES.map((t) => [t.value, t.label]),
);

function uid(): string {
    return Math.random().toString(36).slice(2, 9);
}

function emptyForm(tiers: PriceTier[]): FormState {
    return {
        sku: '',
        name: '',
        barcode: '',
        description: '',
        category_id: '',
        brand_id: '',
        type: 'saleable_retail',
        min_stock: '0',
        is_active: true,
        units: [
            {
                key: uid(),
                unit_id: '',
                level: 1,
                conversion_to_base: '1',
                barcode_per_unit: '',
                prices: tiers.map((t) => ({ price_tier_id: t.id, price: '' })),
            },
        ],
    };
}

// ── Component ────────────────────────────────────────────────────────────

export default function Products({ products, categories, brands, units, tiers, filters }: Props) {
    const [open, setOpen] = useState(false);
    const [tierMgmtOpen, setTierMgmtOpen] = useState(false);
    const [form, setForm] = useState<FormState>(() => emptyForm(tiers));
    const [activeTab, setActiveTab] = useState<'umum' | 'harga' | 'stok'>('umum');
    const [search, setSearch] = useState(filters.search ?? '');
    const [submitting, setSubmitting] = useState(false);
    // Stok per gudang — read-only, di-fetch saat startEdit; null saat create.
    const [inventories, setInventories] = useState<InventoryRow[] | null>(null);
    const isEdit = form.id !== undefined;
    const defaultTier = useMemo(() => tiers.find((t) => t.is_default), [tiers]);

    function startCreate() {
        setForm(emptyForm(tiers));
        setInventories(null);
        setActiveTab('umum');
        setOpen(true);
    }

    async function startEdit(p: ProductRow) {
        try {
            const res = await axios.get<{ product: ProductDetail }>(
                route('master.products.show', p.id),
            );
            const d = res.data.product;

            const unitRows: UnitRow[] = d.units
                .sort((a, b) => a.level - b.level)
                .map((u) => ({
                    key: uid(),
                    unit_id: u.unit_id,
                    level: u.level,
                    conversion_to_base: inputQty(u.conversion_to_base),
                    barcode_per_unit: u.barcode_per_unit ?? '',
                    prices: tiers.map((t) => {
                        const existing = u.prices.find((p) => p.price_tier_id === t.id);
                        return {
                            price_tier_id: t.id,
                            price: existing ? inputMoney(existing.price) : '',
                        };
                    }),
                }));

            setForm({
                id: d.id,
                sku: d.sku,
                name: d.name,
                barcode: d.barcode ?? '',
                description: d.description ?? '',
                category_id: String(d.category_id ?? ''),
                brand_id: d.brand_id ? String(d.brand_id) : '',
                type: d.type,
                min_stock: inputQty(d.min_stock ?? '0'),
                is_active: d.is_active,
                units: unitRows,
            });
            setInventories(d.inventories ?? []);
            setActiveTab('umum');
            setOpen(true);
        } catch (e) {
            toast.error('Gagal memuat detail produk');
        }
    }

    function addUnit() {
        const nextLevel = Math.max(...form.units.map((u) => u.level), 0) + 1;
        setForm((f) => ({
            ...f,
            units: [
                ...f.units,
                {
                    key: uid(),
                    unit_id: '',
                    level: nextLevel,
                    conversion_to_base: '',
                    barcode_per_unit: '',
                    prices: tiers.map((t) => ({ price_tier_id: t.id, price: '' })),
                },
            ],
        }));
    }

    function removeUnit(key: string) {
        setForm((f) => ({ ...f, units: f.units.filter((u) => u.key !== key) }));
    }

    function patchUnit(key: string, patch: Partial<UnitRow>) {
        setForm((f) => ({
            ...f,
            units: f.units.map((u) => (u.key === key ? { ...u, ...patch } : u)),
        }));
    }

    function patchPrice(unitKey: string, tierId: number, value: string) {
        setForm((f) => ({
            ...f,
            units: f.units.map((u) => {
                if (u.key !== unitKey) return u;
                return {
                    ...u,
                    prices: u.prices.map((p) =>
                        p.price_tier_id === tierId ? { ...p, price: value } : p,
                    ),
                };
            }),
        }));
    }

    function submit(e: FormEvent) {
        e.preventDefault();

        const payload = {
            sku: form.sku,
            name: form.name,
            barcode: form.barcode || null,
            description: form.description || null,
            category_id: form.category_id ? Number(form.category_id) : null,
            brand_id: form.brand_id ? Number(form.brand_id) : null,
            type: form.type,
            min_stock: form.min_stock ? Number(form.min_stock) : 0,
            is_active: form.is_active,
            units: form.units.map((u) => ({
                unit_id: u.unit_id === '' ? null : Number(u.unit_id),
                level: u.level,
                conversion_to_base: Number(u.conversion_to_base),
                barcode_per_unit: u.barcode_per_unit || null,
                // Drop empty price cells — server akan fallback otomatis.
                prices: u.prices
                    .filter((p) => p.price !== '' && !isNaN(Number(p.price)))
                    .map((p) => ({
                        price_tier_id: p.price_tier_id,
                        price: Number(p.price),
                    })),
            })),
        };

        setSubmitting(true);
        const opts = {
            onSuccess: () => {
                toast.success(isEdit ? 'Produk diperbarui' : 'Produk ditambahkan');
                setOpen(false);
            },
            onError: (errs: Record<string, string>) =>
                toast.error(Object.values(errs)[0] ?? 'Gagal menyimpan'),
            onFinish: () => setSubmitting(false),
        };

        if (isEdit) {
            router.put(route('master.products.update', form.id!), payload, opts);
        } else {
            router.post(route('master.products.store'), payload, opts);
        }
    }

    function destroy(p: ProductRow) {
        if (! confirm(`Hapus / nonaktifkan produk "${p.name}"?`)) return;
        router.delete(route('master.products.destroy', p.id), {
            preserveScroll: true,
            onSuccess: () => toast.success('Produk diproses'),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal hapus'),
        });
    }

    function doSearch(e: FormEvent) {
        e.preventDefault();
        router.get(route('master.products.index'), { search },
            { preserveState: true, preserveScroll: true });
    }

    const isPersediaanType = form.type === 'saleable_retail'
        || form.type === 'raw_material'
        || form.type === 'compoundable_drug';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Master Produk</h2>}>
            <Head title="Master Produk" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <form onSubmit={doSearch} className="flex gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama / SKU / barcode"
                            className="w-72"
                        />
                        <Button type="submit" variant="outline">Cari</Button>
                    </form>
                    <div className="flex gap-2">
                        <Link href={route('master.products.import.show')}>
                            <Button type="button" variant="outline">Import Excel</Button>
                        </Link>
                        <Button type="button" variant="outline" onClick={() => setTierMgmtOpen(true)}>
                            Kelola Tier ({tiers.length})
                        </Button>
                        <Button type="button" onClick={startCreate}>+ Tambah Produk</Button>
                    </div>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>SKU</TableHead>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Kategori</TableHead>
                                    <TableHead>Jenis</TableHead>
                                    <TableHead className="text-right">
                                        Harga ({defaultTier?.name ?? 'default'})
                                    </TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {products.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                            Belum ada produk.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {products.data.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell className="font-mono text-xs">{p.sku}</TableCell>
                                        <TableCell>
                                            <div className="font-medium">{p.name}</div>
                                            {! p.is_active && (
                                                <Badge variant="muted" className="mt-1 text-xs">nonaktif</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{p.category?.name ?? '-'}</TableCell>
                                        <TableCell>
                                            <Badge variant="muted">{TYPE_LABEL[p.type] ?? p.type}</Badge>
                                        </TableCell>
                                        <TableCell className="text-right">{rupiah(p.price)}</TableCell>
                                        <TableCell className="text-right space-x-1">
                                            <Button size="sm" variant="ghost" onClick={() => startEdit(p)}>
                                                Edit
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => destroy(p)}>
                                                Hapus
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {products.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>{products.from}–{products.to} dari {products.total}</div>
                        <div className="flex gap-1">
                            {products.links.map((l, i) => (
                                <Button
                                    key={i}
                                    variant={l.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={! l.url}
                                    onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true })}
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* ─── Form Modal ─────────────────────────────────────────── */}
            <Dialog open={open} onOpenChange={(o) => ! submitting && setOpen(o)}>
                <DialogContent className="max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>{isEdit ? 'Edit Produk' : 'Tambah Produk'}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <Tabs value={activeTab} onValueChange={(v) => setActiveTab(v as typeof activeTab)}>
                            <TabsList>
                                <TabsTrigger value="umum">Umum</TabsTrigger>
                                <TabsTrigger value="harga">Harga</TabsTrigger>
                                <TabsTrigger value="stok">Stok</TabsTrigger>
                            </TabsList>

                            {/* ─── Tab Umum ──────────────────────────── */}
                            <TabsContent value="umum" className="space-y-3">
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label htmlFor="f-name">Nama *</Label>
                                        <Input id="f-name" value={form.name}
                                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                                            required maxLength={255} />
                                    </div>
                                    <div>
                                        <Label htmlFor="f-sku">Kode Barang (SKU) *</Label>
                                        <Input id="f-sku" value={form.sku}
                                            onChange={(e) => setForm({ ...form, sku: e.target.value })}
                                            required maxLength={64} disabled={isEdit} />
                                    </div>
                                    <div>
                                        <Label htmlFor="f-cat">Kategori *</Label>
                                        <select id="f-cat"
                                            value={form.category_id}
                                            onChange={(e) => setForm({ ...form, category_id: e.target.value })}
                                            required
                                            className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                            <option value="">— pilih —</option>
                                            {categories.map((c) => (
                                                <option key={c.id} value={c.id}>{c.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <Label htmlFor="f-brand">Merek</Label>
                                        <select id="f-brand"
                                            value={form.brand_id}
                                            onChange={(e) => setForm({ ...form, brand_id: e.target.value })}
                                            className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                            <option value="">—</option>
                                            {brands.map((b) => (
                                                <option key={b.id} value={b.id}>{b.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <Label htmlFor="f-type">Jenis *</Label>
                                        <select id="f-type"
                                            value={form.type}
                                            onChange={(e) => setForm({ ...form, type: e.target.value })}
                                            className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                            {PRODUCT_TYPES.map((t) => (
                                                <option key={t.value} value={t.value}>{t.label}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <Label htmlFor="f-barcode">UPC / Barcode</Label>
                                        <Input id="f-barcode" value={form.barcode}
                                            onChange={(e) => setForm({ ...form, barcode: e.target.value })} />
                                    </div>
                                </div>

                                <div className="border-t pt-3">
                                    <div className="mb-2 flex items-center justify-between">
                                        <h4 className="text-sm font-semibold">Satuan</h4>
                                        <Button type="button" size="sm" variant="outline" onClick={addUnit}>
                                            + Tambah Satuan
                                        </Button>
                                    </div>
                                    <div className="space-y-2">
                                        {form.units.map((u) => {
                                            const isBase = u.level === 1;
                                            const baseUnit = form.units.find((x) => x.level === 1);
                                            const baseCode = baseUnit && baseUnit.unit_id !== ''
                                                ? units.find((m) => m.id === baseUnit.unit_id)?.code ?? 'base'
                                                : 'base';
                                            return (
                                                <div key={u.key} className="grid grid-cols-12 items-end gap-2 rounded-md border p-2">
                                                    <div className="col-span-3">
                                                        <Label className="text-xs">
                                                            Satuan {isBase && <span className="text-muted-foreground">(base — terkunci)</span>}
                                                        </Label>
                                                        <select
                                                            value={u.unit_id}
                                                            onChange={(e) => patchUnit(u.key, { unit_id: e.target.value === '' ? '' : Number(e.target.value) })}
                                                            className="flex h-10 w-full rounded-md border border-input bg-background px-2 text-sm">
                                                            <option value="">—</option>
                                                            {units.map((m) => (
                                                                <option key={m.id} value={m.id}>{m.code} — {m.name}</option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                    <div className="col-span-2">
                                                        <Label className="text-xs">Rasio ke base</Label>
                                                        <Input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            value={isBase ? '1' : u.conversion_to_base}
                                                            disabled={isBase}
                                                            onChange={(e) => patchUnit(u.key, { conversion_to_base: e.target.value })}
                                                            className="h-10 text-sm"
                                                        />
                                                    </div>
                                                    <div className="col-span-3 text-xs text-muted-foreground">
                                                        {isBase
                                                            ? '= 1 base'
                                                            : u.conversion_to_base && Number(u.conversion_to_base) > 0
                                                                ? `= ${formatQty(u.conversion_to_base)} ${baseCode}`
                                                                : '— set rasio'}
                                                    </div>
                                                    <div className="col-span-3">
                                                        <Label className="text-xs">Barcode satuan</Label>
                                                        <Input
                                                            value={u.barcode_per_unit}
                                                            onChange={(e) => patchUnit(u.key, { barcode_per_unit: e.target.value })}
                                                            className="h-10 text-sm"
                                                            placeholder="(opsional)"
                                                        />
                                                    </div>
                                                    <div className="col-span-1 text-right">
                                                        {! isBase && (
                                                            <Button type="button" size="sm" variant="ghost"
                                                                onClick={() => removeUnit(u.key)}>
                                                                ×
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            </TabsContent>

                            {/* ─── Tab Harga ─────────────────────────── */}
                            <TabsContent value="harga" className="space-y-3">
                                <p className="text-sm text-muted-foreground">
                                    Cell kosong = fallback ke <strong>{defaultTier?.name ?? 'default'}</strong>{' '}
                                    untuk satuan yg sama. Tier <strong>{defaultTier?.name ?? 'default'}</strong> base unit
                                    <strong> wajib</strong> diisi.
                                </p>
                                <div className="overflow-x-auto">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="min-w-[140px]">Satuan</TableHead>
                                                {tiers.map((t) => (
                                                    <TableHead key={t.id} className="text-right min-w-[140px]">
                                                        {t.name}
                                                        {t.is_default && (
                                                            <Badge variant="info" className="ml-1 text-[10px]">default</Badge>
                                                        )}
                                                    </TableHead>
                                                ))}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {form.units.map((u) => {
                                                const unitMeta = units.find((m) => m.id === u.unit_id);
                                                const label = unitMeta
                                                    ? `${unitMeta.code} (lvl ${u.level})`
                                                    : `(pilih satuan dulu)`;
                                                return (
                                                    <TableRow key={u.key}>
                                                        <TableCell className="font-medium">
                                                            {label}
                                                            {u.level === 1 && (
                                                                <Badge variant="muted" className="ml-1 text-[10px]">base</Badge>
                                                            )}
                                                        </TableCell>
                                                        {tiers.map((t) => {
                                                            const cell = u.prices.find((p) => p.price_tier_id === t.id)
                                                                ?? { price_tier_id: t.id, price: '' };
                                                            const isRequired = u.level === 1 && t.is_default;
                                                            return (
                                                                <TableCell key={t.id} className="text-right">
                                                                    <Input
                                                                        type="number"
                                                                        step="0.01"
                                                                        min="0"
                                                                        value={cell.price}
                                                                        onChange={(e) => patchPrice(u.key, t.id, e.target.value)}
                                                                        className="h-9 text-right text-sm"
                                                                        placeholder={isRequired ? 'wajib' : 'fallback'}
                                                                        required={isRequired}
                                                                    />
                                                                </TableCell>
                                                            );
                                                        })}
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>
                            </TabsContent>

                            {/* ─── Tab Stok ──────────────────────────── */}
                            <TabsContent value="stok" className="space-y-3">
                                <div className="max-w-xs">
                                    <Label htmlFor="f-min">Stok Minimum</Label>
                                    <Input id="f-min" type="number" step="0.01" min="0"
                                        value={form.min_stock}
                                        onChange={(e) => setForm({ ...form, min_stock: e.target.value })} />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Alert kalau stok jatuh ≤ angka ini.
                                    </p>
                                </div>
                                {isPersediaanType && (
                                    <p className="text-xs text-muted-foreground">
                                        Stok awal per gudang diisi lewat{' '}
                                        <Link href={route('inventory.stock')} className="text-sky-700 hover:underline">
                                            Stock Adjustment
                                        </Link>{' '}
                                        atau Opname setelah produk dibuat.
                                    </p>
                                )}

                                {isEdit && inventories !== null && (
                                    <div className="space-y-2 pt-2">
                                        <Label>Stok per Gudang</Label>
                                        {inventories.length === 0 ? (
                                            <p className="text-sm text-muted-foreground">
                                                Belum ada stok di gudang manapun.
                                            </p>
                                        ) : (
                                            <div className="rounded-md border">
                                                <Table>
                                                    <TableHeader>
                                                        <TableRow>
                                                            <TableHead>Gudang</TableHead>
                                                            <TableHead>Tipe</TableHead>
                                                            <TableHead className="text-right">Qty</TableHead>
                                                            <TableHead className="text-right">HPP Rata2</TableHead>
                                                            <TableHead className="text-right">Aksi</TableHead>
                                                        </TableRow>
                                                    </TableHeader>
                                                    <TableBody>
                                                        {inventories.map((inv) => (
                                                            <TableRow key={inv.id}>
                                                                <TableCell>
                                                                    <div className="font-medium">
                                                                        {inv.warehouse?.name ?? '—'}
                                                                    </div>
                                                                    <div className="text-xs text-muted-foreground">
                                                                        {inv.warehouse?.code}
                                                                        {! inv.warehouse?.is_active && (
                                                                            <span className="ml-1 text-amber-700">(nonaktif)</span>
                                                                        )}
                                                                    </div>
                                                                </TableCell>
                                                                <TableCell className="text-sm">
                                                                    {inv.warehouse?.warehouse_type ?? '—'}
                                                                </TableCell>
                                                                <TableCell className="text-right font-mono">
                                                                    {formatQty(inv.qty)}
                                                                </TableCell>
                                                                <TableCell className="text-right">
                                                                    {rupiah(inv.cost_avg)}
                                                                </TableCell>
                                                                <TableCell className="text-right">
                                                                    {form.id && (
                                                                        <Link
                                                                            href={
                                                                                route('inventory.stock_card', form.id) +
                                                                                (inv.warehouse?.id
                                                                                    ? `?warehouse_id=${inv.warehouse.id}`
                                                                                    : '')
                                                                            }
                                                                            className="text-xs text-sky-700 hover:underline"
                                                                        >
                                                                            Kartu Stok →
                                                                        </Link>
                                                                    )}
                                                                </TableCell>
                                                            </TableRow>
                                                        ))}
                                                    </TableBody>
                                                </Table>
                                            </div>
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            Read-only — perubahan stok lewat Pembelian (PO), Opname, atau Stock Adjustment.
                                        </p>
                                    </div>
                                )}

                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox"
                                        checked={form.is_active}
                                        onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                                    Aktif
                                </label>
                            </TabsContent>
                        </Tabs>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)} disabled={submitting}>
                                Batal
                            </Button>
                            <Button type="submit" disabled={submitting}>
                                {submitting ? 'Menyimpan…' : isEdit ? 'Simpan Perubahan' : 'Tambah Produk'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <TierManagementDialog
                open={tierMgmtOpen}
                onOpenChange={setTierMgmtOpen}
                tiers={tiers}
            />
        </AuthenticatedLayout>
    );
}

// ── Sub-dialog: Kelola Tier ─────────────────────────────────────────────

interface TierMgmtProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tiers: PriceTier[];
}

function TierManagementDialog({ open, onOpenChange, tiers }: TierMgmtProps) {
    const [newName, setNewName] = useState('');
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editName, setEditName] = useState('');

    function addTier(e: FormEvent) {
        e.preventDefault();
        if (! newName.trim()) return;
        router.post(route('master.price_tiers.store'), { name: newName.trim() }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Tier ditambahkan');
                setNewName('');
            },
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    function saveEdit(id: number) {
        router.put(route('master.price_tiers.update', id), { name: editName.trim() }, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Tier diperbarui');
                setEditingId(null);
            },
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    function deleteTier(t: PriceTier) {
        if (t.is_default) {
            toast.error('Tier default tidak bisa dihapus');
            return;
        }
        if (! confirm(`Hapus tier "${t.name}"? Semua harga di tier ini akan hilang.`)) return;
        router.delete(route('master.price_tiers.destroy', t.id), {
            preserveScroll: true,
            onSuccess: () => toast.success('Tier dihapus'),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    function setDefault(t: PriceTier) {
        if (t.is_default) return;
        if (! confirm(`Jadikan "${t.name}" sebagai tier default? Tier lama akan demote.`)) return;
        router.post(route('master.price_tiers.set_default', t.id), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Default sekarang: ${t.name}`),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Kelola Tier Harga</DialogTitle>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">
                    Tier default jadi anchor fallback. Cell harga kosong di tier lain otomatis pakai harga default
                    untuk satuan yg sama.
                </p>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Nama</TableHead>
                            <TableHead>Default</TableHead>
                            <TableHead className="text-right">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {tiers.map((t) => (
                            <TableRow key={t.id}>
                                <TableCell>
                                    {editingId === t.id
                                        ? <Input value={editName} onChange={(e) => setEditName(e.target.value)} className="h-9" />
                                        : t.name}
                                </TableCell>
                                <TableCell>
                                    {t.is_default
                                        ? <Badge variant="info">default</Badge>
                                        : <Button size="sm" variant="ghost" onClick={() => setDefault(t)}>Set default</Button>}
                                </TableCell>
                                <TableCell className="text-right space-x-1">
                                    {editingId === t.id ? (
                                        <>
                                            <Button size="sm" onClick={() => saveEdit(t.id)}>Simpan</Button>
                                            <Button size="sm" variant="ghost" onClick={() => setEditingId(null)}>Batal</Button>
                                        </>
                                    ) : (
                                        <>
                                            <Button size="sm" variant="ghost"
                                                onClick={() => { setEditingId(t.id); setEditName(t.name); }}>
                                                Edit
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => deleteTier(t)}>
                                                Hapus
                                            </Button>
                                        </>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>

                <form onSubmit={addTier} className="flex gap-2 border-t pt-3">
                    <Input
                        value={newName}
                        onChange={(e) => setNewName(e.target.value)}
                        placeholder="Nama tier baru (mis. Grosir / Klinik / VIP)"
                        className="flex-1"
                    />
                    <Button type="submit">+ Tambah Tier</Button>
                </form>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>Tutup</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
