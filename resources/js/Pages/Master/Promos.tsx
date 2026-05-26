import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
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
import { rupiah, inputMoney, inputQty } from '@/lib/utils';

type PromoType = 'periode_discount' | 'per_item' | 'voucher' | 'bundling' | 'tebus_murah';
type DiscountKind = 'percent' | 'nominal';
type DayOfWeek = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

interface Promo {
    id: number;
    name: string;
    type: PromoType;
    discount_kind: DiscountKind;
    discount_value: string;
    max_discount_amount: string | null;
    starts_at: string;
    ends_at: string;
    days_of_week: DayOfWeek[] | null;
    time_start: string | null;
    time_end: string | null;
    discount_coa_id: number | null;
    discount_coa?: { id: number; code: string; name: string } | null;
    min_purchase: string;
    min_qty: number;
    quota_total: number | null;
    quota_used: number;
    is_active: boolean;
    is_stackable: boolean;
    config: {
        product_ids?: number[];
        category_ids?: number[];
        bundle_rules?: Array<{ product_id: number; qty: number }>;
        qualifying_product_ids?: number[];
        qualifying_category_ids?: number[];
        qualifying_min_qty_per_set?: number;
        tebus_product_id?: number;
        tebus_price?: number;
        max_tebus_per_transaction?: number | null;
    } | null;
    voucher_code: string | null;
    warehouses: Array<{ id: number; name: string }>;
}

interface Coa { id: number; code: string; name: string; type: string }
interface Warehouse { id: number; code: string; name: string }
interface ProductLite { id: number; sku: string; name: string; category_id: number | null }
interface CategoryLite { id: number; name: string }

interface ExcelPreviewMatched {
    id: number;
    sku: string;
    name: string;
    row_excel: number;
}
interface ExcelPreviewUnmatched {
    row_excel: number;
    sku: string;
    name_ref: string | null;
}
interface ExcelPreview {
    matched: ExcelPreviewMatched[];
    unmatched: ExcelPreviewUnmatched[];
    summary: {
        total_input: number;
        matched_count: number;
        unmatched_count: number;
        dedup_skipped: number;
        empty_skipped: number;
        truncated: boolean;
    };
}
interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    promos: Paginated<Promo>;
    coas: Coa[];
    warehouses: Warehouse[];
    products: ProductLite[];
    categories: CategoryLite[];
    filters: { search?: string; status?: 'active' | 'inactive' | 'upcoming' };
}

const TYPE_LABEL: Record<PromoType, string> = {
    periode_discount: 'Diskon Periode',
    per_item: 'Diskon Per-Barang',
    voucher: 'Kode Voucher',
    bundling: 'Bundling',
    tebus_murah: 'Tebus Murah',
};

const ENABLED_TYPES: PromoType[] = [
    'periode_discount',
    'per_item',
    'voucher',
    'bundling',
    'tebus_murah',
];

const DAYS: { value: DayOfWeek; label: string }[] = [
    { value: 'mon', label: 'Sen' }, { value: 'tue', label: 'Sel' },
    { value: 'wed', label: 'Rab' }, { value: 'thu', label: 'Kam' },
    { value: 'fri', label: 'Jum' }, { value: 'sat', label: 'Sab' },
    { value: 'sun', label: 'Min' },
];

interface BundleRuleRow { product_id: number | ''; qty: string }

interface FormState {
    id?: number;
    name: string;
    type: PromoType;
    discount_kind: DiscountKind;
    discount_value: string;
    max_discount_amount: string;
    starts_at: string;        // datetime-local
    ends_at: string;
    use_days: boolean;
    days_of_week: DayOfWeek[];
    use_hours: boolean;
    time_start: string;
    time_end: string;
    use_specific_warehouses: boolean;
    warehouse_ids: number[];
    discount_coa_id: string;
    min_purchase: string;
    min_qty: string;
    quota_total: string;
    is_active: boolean;
    is_stackable: boolean;   // false = eksklusif (default, aman); true = numpuk
    product_ids: number[];   // tipe per_item
    category_ids: number[];  // tipe per_item
    product_search: string;  // local filter
    voucher_code: string;    // tipe voucher
    // tipe bundling
    bundle_rules: BundleRuleRow[];
    // tipe tebus_murah
    tebus_product_id: number | '';
    tebus_price: string;
    qualifying_product_ids: number[];
    qualifying_category_ids: number[];
    qualifying_min_qty_per_set: string;
    max_tebus_per_transaction: string;
}

function emptyForm(): FormState {
    const now = new Date();
    const inAWeek = new Date(now.getTime() + 7 * 24 * 60 * 60 * 1000);
    const dt = (d: Date) => d.toISOString().slice(0, 16);

    return {
        name: '',
        type: 'periode_discount',
        discount_kind: 'percent',
        discount_value: '',
        max_discount_amount: '',
        starts_at: dt(now),
        ends_at: dt(inAWeek),
        use_days: false,
        days_of_week: [],
        use_hours: false,
        time_start: '',
        time_end: '',
        use_specific_warehouses: false,
        warehouse_ids: [],
        discount_coa_id: '',
        min_purchase: '0',
        min_qty: '0',
        quota_total: '',
        is_active: true,
        is_stackable: false, // default eksklusif (aman bisnis)
        product_ids: [],
        category_ids: [],
        product_search: '',
        voucher_code: '',
        bundle_rules: [
            { product_id: '', qty: '1' },
            { product_id: '', qty: '1' },
        ],
        tebus_product_id: '',
        tebus_price: '',
        qualifying_product_ids: [],
        qualifying_category_ids: [],
        qualifying_min_qty_per_set: '1',
        max_tebus_per_transaction: '',
    };
}

export default function Promos({ promos, coas, warehouses, products, categories, filters }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(emptyForm());
    const [tab, setTab] = useState<'umum' | 'periode' | 'cabang' | 'akun' | 'syarat' | 'kuota'>('umum');
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState<'' | 'active' | 'inactive' | 'upcoming'>(filters.status ?? '');
    const [submitting, setSubmitting] = useState(false);
    const [excelUploading, setExcelUploading] = useState(false);
    const [excelPreview, setExcelPreview] = useState<ExcelPreview | null>(null);
    const isEdit = form.id !== undefined;

    function startCreate() {
        setForm(emptyForm());
        setExcelPreview(null);
        setTab('umum');
        setOpen(true);
    }

    function startEdit(p: Promo) {
        setExcelPreview(null);
        setForm({
            id: p.id,
            name: p.name,
            type: p.type,
            discount_kind: p.discount_kind,
            discount_value: inputQty(p.discount_value),
            max_discount_amount: p.max_discount_amount ? inputMoney(p.max_discount_amount) : '',
            starts_at: p.starts_at.slice(0, 16),
            ends_at: p.ends_at.slice(0, 16),
            use_days: (p.days_of_week ?? []).length > 0,
            days_of_week: p.days_of_week ?? [],
            use_hours: !! (p.time_start && p.time_end),
            time_start: p.time_start ?? '',
            time_end: p.time_end ?? '',
            use_specific_warehouses: p.warehouses.length > 0,
            warehouse_ids: p.warehouses.map((w) => w.id),
            discount_coa_id: p.discount_coa_id ? String(p.discount_coa_id) : '',
            min_purchase: inputMoney(p.min_purchase),
            min_qty: String(p.min_qty),
            quota_total: p.quota_total ? String(p.quota_total) : '',
            is_active: p.is_active,
            is_stackable: p.is_stackable,
            product_ids: p.config?.product_ids ?? [],
            category_ids: p.config?.category_ids ?? [],
            product_search: '',
            voucher_code: p.voucher_code ?? '',
            bundle_rules: (p.config?.bundle_rules ?? []).length > 0
                ? (p.config!.bundle_rules!).map((r) => ({
                      product_id: r.product_id,
                      qty: String(r.qty),
                  }))
                : [
                      { product_id: '', qty: '1' },
                      { product_id: '', qty: '1' },
                  ],
            tebus_product_id: p.config?.tebus_product_id ?? '',
            tebus_price: p.config?.tebus_price !== undefined
                ? inputMoney(p.config.tebus_price)
                : '',
            qualifying_product_ids: p.config?.qualifying_product_ids ?? [],
            qualifying_category_ids: p.config?.qualifying_category_ids ?? [],
            qualifying_min_qty_per_set: String(p.config?.qualifying_min_qty_per_set ?? 1),
            max_tebus_per_transaction: p.config?.max_tebus_per_transaction != null
                ? String(p.config.max_tebus_per_transaction)
                : '',
        });
        setTab('umum');
        setOpen(true);
    }

    function duplicate(p: Promo) {
        if (! confirm(`Duplikat promo "${p.name}"? Salinan dibuat nonaktif dgn kuota direset 0.`)) return;
        router.post(route('master.promos.duplicate', p.id), {}, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Promo "${p.name}" diduplikasi`),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    function toggleProduct(id: number) {
        setForm((f) => ({
            ...f,
            product_ids: f.product_ids.includes(id)
                ? f.product_ids.filter((x) => x !== id)
                : [...f.product_ids, id],
        }));
    }

    function toggleCategory(id: number) {
        setForm((f) => ({
            ...f,
            category_ids: f.category_ids.includes(id)
                ? f.category_ids.filter((x) => x !== id)
                : [...f.category_ids, id],
        }));
    }

    // ─── BUNDLING helpers ─────────────────────────────────────────────
    function addBundleRow() {
        setForm((f) => ({
            ...f,
            bundle_rules: [...f.bundle_rules, { product_id: '', qty: '1' }],
        }));
    }
    function removeBundleRow(idx: number) {
        setForm((f) => ({
            ...f,
            bundle_rules: f.bundle_rules.filter((_, i) => i !== idx),
        }));
    }
    function updateBundleRow(idx: number, patch: Partial<BundleRuleRow>) {
        setForm((f) => ({
            ...f,
            bundle_rules: f.bundle_rules.map((r, i) => (i === idx ? { ...r, ...patch } : r)),
        }));
    }

    // ─── TEBUS helpers ────────────────────────────────────────────────
    function toggleQualifyingProduct(id: number) {
        setForm((f) => ({
            ...f,
            qualifying_product_ids: f.qualifying_product_ids.includes(id)
                ? f.qualifying_product_ids.filter((x) => x !== id)
                : [...f.qualifying_product_ids, id],
        }));
    }
    function toggleQualifyingCategory(id: number) {
        setForm((f) => ({
            ...f,
            qualifying_category_ids: f.qualifying_category_ids.includes(id)
                ? f.qualifying_category_ids.filter((x) => x !== id)
                : [...f.qualifying_category_ids, id],
        }));
    }

    async function handleExcelUpload(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (! file) return;

        const fd = new FormData();
        fd.append('file', file);

        setExcelUploading(true);
        setExcelPreview(null);
        try {
            const csrf = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';
            const resp = await fetch(route('master.promos.excel_preview'), {
                method: 'POST',
                body: fd,
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            if (! resp.ok) {
                const errBody = await resp.json().catch(() => ({}));
                throw new Error(
                    errBody.message ?? `Upload gagal (${resp.status})`,
                );
            }
            const data = (await resp.json()) as ExcelPreview;
            setExcelPreview(data);
            if (data.summary.matched_count === 0 && data.summary.unmatched_count === 0) {
                toast.warning('File tidak berisi SKU yang bisa diproses.');
            }
        } catch (err: unknown) {
            const msg = err instanceof Error ? err.message : 'Upload gagal';
            toast.error(msg);
        } finally {
            setExcelUploading(false);
            e.target.value = ''; // reset supaya bisa upload file sama berkali-kali
        }
    }

    function applyExcelPreview() {
        if (! excelPreview) return;
        const newIds = excelPreview.matched.map((m) => m.id);
        const beforeCount = form.product_ids.length;
        setForm((f) => ({
            ...f,
            product_ids: Array.from(new Set([...f.product_ids, ...newIds])),
        }));
        // Set timeout supaya state update keprocess, lalu hitung yang benar-benar baru
        const added = Array.from(
            new Set([...form.product_ids, ...newIds]),
        ).length - beforeCount;
        toast.success(
            added > 0
                ? `${added} produk baru ditambahkan ke promo`
                : 'Tidak ada produk baru — semua sudah ada di daftar.',
        );
        setExcelPreview(null);
    }

    function toggleDay(d: DayOfWeek) {
        setForm((f) => ({
            ...f,
            days_of_week: f.days_of_week.includes(d)
                ? f.days_of_week.filter((x) => x !== d)
                : [...f.days_of_week, d],
        }));
    }

    function toggleWarehouse(id: number) {
        setForm((f) => ({
            ...f,
            warehouse_ids: f.warehouse_ids.includes(id)
                ? f.warehouse_ids.filter((x) => x !== id)
                : [...f.warehouse_ids, id],
        }));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        setSubmitting(true);

        const payload: Record<string, any> = {
            name: form.name.trim(),
            type: form.type,
            // Untuk tebus_murah: server auto-fill discount_kind='nominal'+value=1.
            // Tetap kirim dari form supaya validator existing tidak komplain
            // kalau owner kebetulan input value valid.
            discount_kind: form.discount_kind,
            discount_value: Number(form.discount_value) || 1,
            max_discount_amount: form.max_discount_amount ? Number(form.max_discount_amount) : null,
            starts_at: form.starts_at,
            ends_at: form.ends_at,
            days_of_week: form.use_days && form.days_of_week.length > 0 ? form.days_of_week : null,
            time_start: form.use_hours && form.time_start ? form.time_start : null,
            time_end: form.use_hours && form.time_end ? form.time_end : null,
            warehouse_ids: form.use_specific_warehouses ? form.warehouse_ids : [],
            discount_coa_id: form.discount_coa_id ? Number(form.discount_coa_id) : null,
            min_purchase: Number(form.min_purchase) || 0,
            min_qty: Number(form.min_qty) || 0,
            quota_total: form.quota_total ? Number(form.quota_total) : null,
            is_active: form.is_active,
            is_stackable: form.is_stackable,
            // Per-item params (server abaikan kalau type != per_item)
            product_ids: form.type === 'per_item' ? form.product_ids : [],
            category_ids: form.type === 'per_item' ? form.category_ids : [],
            voucher_code: form.type === 'voucher' ? form.voucher_code.toUpperCase().trim() : null,
        };

        if (form.type === 'bundling') {
            payload.bundle_rules = form.bundle_rules
                .filter((r) => r.product_id !== '' && Number(r.qty) > 0)
                .map((r) => ({ product_id: Number(r.product_id), qty: Number(r.qty) }));
        }

        if (form.type === 'tebus_murah') {
            payload.tebus_product_id = form.tebus_product_id || null;
            payload.tebus_price = form.tebus_price !== '' ? Number(form.tebus_price) : null;
            payload.qualifying_product_ids = form.qualifying_product_ids;
            payload.qualifying_category_ids = form.qualifying_category_ids;
            payload.qualifying_min_qty_per_set = Number(form.qualifying_min_qty_per_set) || 1;
            payload.max_tebus_per_transaction = form.max_tebus_per_transaction
                ? Number(form.max_tebus_per_transaction)
                : null;
        }

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(isEdit ? 'Promo diperbarui' : 'Promo ditambahkan');
                setOpen(false);
            },
            onError: (errs: Record<string, string>) =>
                toast.error(Object.values(errs)[0] ?? 'Gagal'),
            onFinish: () => setSubmitting(false),
        };

        if (isEdit) {
            router.put(route('master.promos.update', form.id!), payload, opts);
        } else {
            router.post(route('master.promos.store'), payload, opts);
        }
    }

    function destroy(p: Promo) {
        const msg = p.quota_used > 0
            ? `Promo "${p.name}" pernah dipakai ${p.quota_used}× — akan dinonaktifkan (jaga histori).`
            : `Hapus promo "${p.name}"?`;
        if (! confirm(msg)) return;
        router.delete(route('master.promos.destroy', p.id), {
            preserveScroll: true,
            onSuccess: () => toast.success('Promo diproses'),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    function doSearch(e: FormEvent) {
        e.preventDefault();
        router.get(route('master.promos.index'),
            { search: search || undefined, status: status || undefined },
            { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Master Promo</h2>}>
            <Head title="Master Promo" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <form onSubmit={doSearch} className="flex gap-2">
                        <Input value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama promo" className="w-72" />
                        <select value={status}
                            onChange={(e) => setStatus(e.target.value as '' | 'active' | 'inactive' | 'upcoming')}
                            className="flex h-11 rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Semua</option>
                            <option value="active">Aktif</option>
                            <option value="upcoming">Akan Datang</option>
                            <option value="inactive">Nonaktif / Lewat</option>
                        </select>
                        <Button type="submit" variant="outline">Cari</Button>
                    </form>
                    <Button type="button" onClick={startCreate}>+ Tambah Promo</Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Tipe</TableHead>
                                    <TableHead>Periode</TableHead>
                                    <TableHead>Diskon</TableHead>
                                    <TableHead>Kuota</TableHead>
                                    <TableHead>Cabang</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {promos.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="text-center text-muted-foreground">
                                            Belum ada promo.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {promos.data.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell className="font-medium">
                                            <div className="flex items-center gap-1">
                                                {p.name}
                                                {p.is_stackable && (
                                                    <Badge variant="info" className="text-[10px]">stackable</Badge>
                                                )}
                                            </div>
                                            {p.voucher_code && (
                                                <div className="font-mono text-[10px] text-sky-700">
                                                    kode: {p.voucher_code}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="muted">{TYPE_LABEL[p.type]}</Badge>
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {p.starts_at.slice(0, 10)} → {p.ends_at.slice(0, 10)}
                                        </TableCell>
                                        <TableCell>
                                            {p.discount_kind === 'percent'
                                                ? `${p.discount_value}%${p.max_discount_amount ? ` (max ${rupiah(p.max_discount_amount)})` : ''}`
                                                : rupiah(p.discount_value)}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {p.quota_total === null
                                                ? `${p.quota_used} (unlimited)`
                                                : `${p.quota_used} / ${p.quota_total}`}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {p.warehouses.length === 0 ? 'Semua' : `${p.warehouses.length} cabang`}
                                        </TableCell>
                                        <TableCell>
                                            {p.is_active
                                                ? <Badge variant="success">aktif</Badge>
                                                : <Badge variant="muted">nonaktif</Badge>}
                                        </TableCell>
                                        <TableCell className="text-right space-x-1">
                                            <Button size="sm" variant="ghost" onClick={() => startEdit(p)}>
                                                Edit
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => duplicate(p)}>
                                                Duplikat
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => destroy(p)}>
                                                {p.quota_used > 0 ? 'Nonaktif' : 'Hapus'}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {promos.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>{promos.from}–{promos.to} dari {promos.total}</div>
                        <div className="flex gap-1">
                            {promos.links.map((l, i) => (
                                <Button key={i} variant={l.active ? 'default' : 'outline'}
                                    size="sm" disabled={! l.url}
                                    onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true })}
                                    dangerouslySetInnerHTML={{ __html: l.label }} />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <Dialog open={open} onOpenChange={(o) => ! submitting && setOpen(o)}>
                <DialogContent className="max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{isEdit ? 'Edit Promo' : 'Tambah Promo'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <Tabs value={tab} onValueChange={(v) => setTab(v as typeof tab)}>
                            <TabsList>
                                <TabsTrigger value="umum">Umum</TabsTrigger>
                                <TabsTrigger value="periode">Periode</TabsTrigger>
                                <TabsTrigger value="cabang">Cabang</TabsTrigger>
                                <TabsTrigger value="akun">Akun (COA)</TabsTrigger>
                                <TabsTrigger value="syarat">Syarat</TabsTrigger>
                                <TabsTrigger value="kuota">Kuota</TabsTrigger>
                            </TabsList>

                            <TabsContent value="umum" className="space-y-3">
                                <div>
                                    <Label htmlFor="pn">Nama Promo *</Label>
                                    <Input id="pn" value={form.name} autoFocus
                                        onChange={(e) => setForm({ ...form, name: e.target.value })}
                                        required maxLength={255} />
                                </div>
                                <div>
                                    <Label htmlFor="pt">Tipe</Label>
                                    <select id="pt" value={form.type}
                                        onChange={(e) => setForm({ ...form, type: e.target.value as PromoType })}
                                        className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                        {Object.entries(TYPE_LABEL).map(([v, l]) => (
                                            <option key={v} value={v}
                                                disabled={! ENABLED_TYPES.includes(v as PromoType)}>
                                                {l}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Fase ini: <strong>Diskon Periode</strong> (full transaksi) +
                                        <strong> Per-Barang</strong> (item spesifik) enabled. 3 tipe lain menyusul.
                                    </p>
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label>Jenis Diskon</Label>
                                        <div className="flex gap-2">
                                            {(['percent', 'nominal'] as DiscountKind[]).map((k) => (
                                                <button type="button" key={k}
                                                    onClick={() => setForm({ ...form, discount_kind: k })}
                                                    className={`flex-1 rounded-md border p-2 text-sm font-medium ${
                                                        form.discount_kind === k
                                                            ? 'border-primary bg-primary/10 ring-2 ring-primary'
                                                            : 'border-input hover:bg-muted/50'
                                                    }`}>
                                                    {k === 'percent' ? '% Persen' : 'Rp Nominal'}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                    <div>
                                        <Label htmlFor="dv">
                                            Nilai * {form.discount_kind === 'percent' ? '(%)' : '(Rp)'}
                                        </Label>
                                        <Input id="dv" type="number" step="0.01" min="0.01" required
                                            value={form.discount_value}
                                            onChange={(e) => setForm({ ...form, discount_value: e.target.value })} />
                                    </div>
                                </div>
                                {form.discount_kind === 'percent' && (
                                    <div>
                                        <Label htmlFor="cap">Cap Diskon Max (Rp) — opsional</Label>
                                        <Input id="cap" type="number" step="0.01" min="0"
                                            placeholder="kosong = no cap"
                                            value={form.max_discount_amount}
                                            onChange={(e) => setForm({ ...form, max_discount_amount: e.target.value })} />
                                    </div>
                                )}
                                {form.type === 'per_item' && (
                                    <div className="border-t pt-3 space-y-3">
                                        <div>
                                            <Label className="text-sm font-semibold">Berlaku untuk</Label>
                                            <p className="text-xs text-muted-foreground">
                                                Pilih produk dan/atau kategori. Item match dapat diskon, item lain harga normal.
                                                Minimal 1 dipilih.
                                            </p>
                                        </div>

                                        {categories.length > 0 && (
                                            <div>
                                                <Label className="text-xs">Kategori (semua produk di dalamnya match)</Label>
                                                <div className="flex flex-wrap gap-1 rounded-md border p-2">
                                                    {categories.map((c) => (
                                                        <button type="button" key={c.id}
                                                            onClick={() => toggleCategory(c.id)}
                                                            className={`rounded-md border px-2 py-1 text-xs ${
                                                                form.category_ids.includes(c.id)
                                                                    ? 'border-primary bg-primary/10 ring-1 ring-primary'
                                                                    : 'border-input hover:bg-muted/50'
                                                            }`}>
                                                            {c.name}
                                                        </button>
                                                    ))}
                                                </div>
                                                {form.category_ids.length > 0 && (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {form.category_ids.length} kategori dipilih
                                                    </p>
                                                )}
                                            </div>
                                        )}

                                        <div>
                                            <Label className="text-xs">Produk spesifik</Label>
                                            <Input
                                                placeholder="Cari produk by nama / SKU"
                                                value={form.product_search}
                                                onChange={(e) => setForm({ ...form, product_search: e.target.value })}
                                                className="mb-1"
                                            />
                                            <div className="max-h-48 overflow-y-auto rounded-md border p-2 space-y-0.5">
                                                {products
                                                    .filter((p) => {
                                                        if (! form.product_search) return true;
                                                        const t = form.product_search.toLowerCase();
                                                        return p.name.toLowerCase().includes(t)
                                                            || p.sku.toLowerCase().includes(t);
                                                    })
                                                    .slice(0, 50)
                                                    .map((p) => (
                                                        <label key={p.id} className="flex items-center gap-2 text-xs hover:bg-muted/30 rounded p-1">
                                                            <input type="checkbox"
                                                                checked={form.product_ids.includes(p.id)}
                                                                onChange={() => toggleProduct(p.id)} />
                                                            <span className="font-mono text-[10px] text-muted-foreground">{p.sku}</span>
                                                            <span>{p.name}</span>
                                                        </label>
                                                    ))}
                                                {products.filter((p) => {
                                                    if (! form.product_search) return true;
                                                    const t = form.product_search.toLowerCase();
                                                    return p.name.toLowerCase().includes(t) || p.sku.toLowerCase().includes(t);
                                                }).length > 50 && (
                                                    <p className="text-[10px] text-muted-foreground">
                                                        … lebih dari 50 hasil, refine pencarian.
                                                    </p>
                                                )}
                                            </div>
                                            {form.product_ids.length > 0 && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {form.product_ids.length} produk dipilih
                                                </p>
                                            )}
                                        </div>

                                        {form.discount_kind === 'percent' && form.max_discount_amount && (
                                            <p className="text-xs text-amber-700">
                                                ⓘ Cap diskon di-apply <strong>per item match</strong> (bukan total transaksi).
                                            </p>
                                        )}

                                        <div className="border-t pt-3 space-y-2">
                                            <Label className="text-xs font-semibold">
                                                Upload daftar produk via Excel (opsional)
                                            </Label>
                                            <p className="text-xs text-muted-foreground">
                                                Untuk bulk pilih banyak produk, download template lalu upload kembali setelah diisi.
                                                Hasil upload <strong>DITAMBAHKAN</strong> ke pilihan saat ini (tidak menggantikan).
                                            </p>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <a
                                                    href={route('master.promos.excel_template')}
                                                    className="inline-flex h-8 items-center rounded border border-input bg-background px-3 text-xs font-medium hover:bg-muted"
                                                >
                                                    ⇩ Download Template
                                                </a>
                                                <label
                                                    className={
                                                        'inline-flex h-8 items-center rounded border border-input bg-background px-3 text-xs font-medium ' +
                                                        (excelUploading
                                                            ? 'cursor-wait opacity-50'
                                                            : 'cursor-pointer hover:bg-muted')
                                                    }
                                                >
                                                    <input
                                                        type="file"
                                                        accept=".xlsx,.xls"
                                                        className="hidden"
                                                        onChange={handleExcelUpload}
                                                        disabled={excelUploading}
                                                    />
                                                    {excelUploading ? 'Memproses…' : '⇧ Upload Excel'}
                                                </label>
                                            </div>

                                            {excelPreview && (
                                                <div className="mt-2 space-y-2 rounded-md border bg-muted/30 p-3">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <div className="text-xs">
                                                            <strong className="text-green-700">
                                                                {excelPreview.summary.matched_count} match
                                                            </strong>
                                                            {' · '}
                                                            <strong className="text-amber-700">
                                                                {excelPreview.summary.unmatched_count} tidak ketemu
                                                            </strong>
                                                            {excelPreview.summary.dedup_skipped > 0 && (
                                                                <> · {excelPreview.summary.dedup_skipped} duplikat di-skip</>
                                                            )}
                                                            {excelPreview.summary.empty_skipped > 0 && (
                                                                <> · {excelPreview.summary.empty_skipped} baris kosong</>
                                                            )}
                                                            {excelPreview.summary.truncated && (
                                                                <> · <span className="text-red-600">file dipotong (file terlalu besar)</span></>
                                                            )}
                                                        </div>
                                                        <button
                                                            type="button"
                                                            className="text-xs text-muted-foreground hover:underline"
                                                            onClick={() => setExcelPreview(null)}
                                                        >
                                                            × Batal
                                                        </button>
                                                    </div>

                                                    {excelPreview.matched.length > 0 && (
                                                        <details className="text-xs">
                                                            <summary className="cursor-pointer text-green-700 hover:underline">
                                                                ✓ {excelPreview.matched.length} produk match (klik untuk lihat)
                                                            </summary>
                                                            <ul className="mt-1 max-h-32 space-y-0.5 overflow-y-auto rounded border bg-white p-2">
                                                                {excelPreview.matched.map((m) => (
                                                                    <li key={m.id} className="flex gap-2">
                                                                        <span className="font-mono text-[10px] text-muted-foreground">
                                                                            {m.sku}
                                                                        </span>
                                                                        <span>{m.name}</span>
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </details>
                                                    )}

                                                    {excelPreview.unmatched.length > 0 && (
                                                        <details className="text-xs" open>
                                                            <summary className="cursor-pointer text-amber-700 hover:underline">
                                                                ⚠ {excelPreview.unmatched.length} SKU tidak ketemu (klik untuk lihat)
                                                            </summary>
                                                            <ul className="mt-1 max-h-32 space-y-0.5 overflow-y-auto rounded border bg-amber-50 p-2">
                                                                {excelPreview.unmatched.map((u, i) => (
                                                                    <li key={i} className="flex gap-2">
                                                                        <span className="text-muted-foreground">
                                                                            baris {u.row_excel}:
                                                                        </span>
                                                                        <span className="font-mono text-[10px]">{u.sku}</span>
                                                                        {u.name_ref && (
                                                                            <span className="italic text-muted-foreground">
                                                                                ({u.name_ref})
                                                                            </span>
                                                                        )}
                                                                    </li>
                                                                ))}
                                                            </ul>
                                                        </details>
                                                    )}

                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            onClick={applyExcelPreview}
                                                            disabled={excelPreview.matched.length === 0}
                                                        >
                                                            + Tambahkan {excelPreview.matched.length} produk ke promo
                                                        </Button>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {form.type === 'bundling' && (
                                    <div className="border-t pt-3 space-y-3">
                                        <div>
                                            <Label className="text-sm font-semibold">Komponen Bundle</Label>
                                            <p className="text-xs text-muted-foreground">
                                                Beli kombinasi produk ini sekaligus → dapat diskon paket.
                                                Min 2 baris. Produk harus unik (1 produk 1 baris).
                                                Bundle kepicu N kali kalau cart punya kelipatan N dari semua komponen.
                                            </p>
                                        </div>

                                        <div className="space-y-1">
                                            {form.bundle_rules.map((row, idx) => (
                                                <div key={idx} className="flex gap-2 items-end">
                                                    <div className="flex-1">
                                                        <Label className="text-xs">Produk</Label>
                                                        <select
                                                            value={row.product_id}
                                                            onChange={(e) =>
                                                                updateBundleRow(idx, {
                                                                    product_id: e.target.value === ''
                                                                        ? ''
                                                                        : Number(e.target.value),
                                                                })
                                                            }
                                                            className="block h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                        >
                                                            <option value="">— pilih produk —</option>
                                                            {products.map((p) => (
                                                                <option key={p.id} value={p.id}>
                                                                    {p.sku} — {p.name}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                    <div className="w-24">
                                                        <Label className="text-xs">Qty</Label>
                                                        <Input
                                                            type="number"
                                                            step="0.0001"
                                                            min="0.0001"
                                                            value={row.qty}
                                                            onChange={(e) =>
                                                                updateBundleRow(idx, { qty: e.target.value })
                                                            }
                                                        />
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => removeBundleRow(idx)}
                                                        disabled={form.bundle_rules.length <= 2}
                                                        title={form.bundle_rules.length <= 2
                                                            ? 'Min 2 komponen'
                                                            : 'Hapus baris'}
                                                    >
                                                        ×
                                                    </Button>
                                                </div>
                                            ))}
                                        </div>

                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={addBundleRow}
                                        >
                                            + Tambah Komponen
                                        </Button>

                                        <p className="text-xs text-amber-700">
                                            ⓘ Cap diskon di-apply <strong>per set</strong> bundle.
                                            Kalau 2 set kepicu → cap × 2.
                                        </p>
                                    </div>
                                )}

                                {form.type === 'tebus_murah' && (
                                    <div className="border-t pt-3 space-y-4">
                                        <div>
                                            <Label className="text-sm font-semibold">Tebus Murah</Label>
                                            <p className="text-xs text-muted-foreground">
                                                Customer beli syarat → boleh tebus produk lain dengan harga
                                                khusus. Kasir SCAN produk tebus di harga normal — sistem
                                                otomatis kasih diskon = (harga normal − harga tebus) × qty
                                                tebus. Diskon hanya berlaku kalau syarat kepenuhi DAN produk
                                                tebus ada di cart.
                                            </p>
                                        </div>

                                        <div>
                                            <Label className="text-xs font-semibold">
                                                Syarat — produk/kategori (opsional)
                                            </Label>
                                            <p className="text-[10px] text-muted-foreground mb-1">
                                                Kosongkan kalau syarat cukup pakai min belanja/qty di tab "Syarat".
                                            </p>

                                            {categories.length > 0 && (
                                                <div className="mb-2">
                                                    <Label className="text-[10px]">Kategori syarat</Label>
                                                    <div className="flex flex-wrap gap-1 rounded-md border p-2">
                                                        {categories.map((c) => (
                                                            <button
                                                                type="button"
                                                                key={c.id}
                                                                onClick={() => toggleQualifyingCategory(c.id)}
                                                                className={`rounded-md border px-2 py-1 text-xs ${
                                                                    form.qualifying_category_ids.includes(c.id)
                                                                        ? 'border-primary bg-primary/10 ring-1 ring-primary'
                                                                        : 'border-input hover:bg-muted/50'
                                                                }`}
                                                            >
                                                                {c.name}
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                            <div>
                                                <Label className="text-[10px]">Produk syarat spesifik</Label>
                                                <div className="max-h-32 overflow-y-auto rounded-md border p-2 space-y-0.5">
                                                    {products.slice(0, 30).map((p) => (
                                                        <label
                                                            key={p.id}
                                                            className="flex items-center gap-2 text-xs hover:bg-muted/30 rounded p-1"
                                                        >
                                                            <input
                                                                type="checkbox"
                                                                checked={form.qualifying_product_ids.includes(p.id)}
                                                                onChange={() => toggleQualifyingProduct(p.id)}
                                                            />
                                                            <span className="font-mono text-[10px] text-muted-foreground">
                                                                {p.sku}
                                                            </span>
                                                            <span>{p.name}</span>
                                                        </label>
                                                    ))}
                                                </div>
                                            </div>

                                            <div className="mt-2">
                                                <Label className="text-[10px]">Qty syarat per 1 set tebus</Label>
                                                <Input
                                                    type="number"
                                                    min="1"
                                                    step="1"
                                                    value={form.qualifying_min_qty_per_set}
                                                    onChange={(e) =>
                                                        setForm({
                                                            ...form,
                                                            qualifying_min_qty_per_set: e.target.value,
                                                        })
                                                    }
                                                    className="max-w-[120px]"
                                                />
                                                <p className="text-[10px] text-muted-foreground mt-1">
                                                    Default 1. Mis: 3 → beli 3 unit syarat → boleh tebus 1 unit.
                                                    Beli 6 → boleh tebus 2.
                                                </p>
                                            </div>
                                        </div>

                                        <div className="border-t pt-3">
                                            <Label className="text-xs font-semibold">Produk Tebus *</Label>
                                            <select
                                                value={form.tebus_product_id}
                                                onChange={(e) =>
                                                    setForm({
                                                        ...form,
                                                        tebus_product_id: e.target.value === ''
                                                            ? ''
                                                            : Number(e.target.value),
                                                    })
                                                }
                                                className="mt-1 block h-9 w-full rounded-md border border-input bg-background px-2 text-sm"
                                                required={form.type === 'tebus_murah'}
                                            >
                                                <option value="">— pilih produk tebus —</option>
                                                {products
                                                    .filter((p) => ! form.qualifying_product_ids.includes(p.id))
                                                    .map((p) => (
                                                        <option key={p.id} value={p.id}>
                                                            {p.sku} — {p.name}
                                                        </option>
                                                    ))}
                                            </select>

                                            <div className="mt-2 grid grid-cols-2 gap-2">
                                                <div>
                                                    <Label className="text-[10px]">Harga Tebus (Rp) *</Label>
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        step="1"
                                                        value={form.tebus_price}
                                                        onChange={(e) =>
                                                            setForm({ ...form, tebus_price: e.target.value })
                                                        }
                                                        placeholder="5000"
                                                        required={form.type === 'tebus_murah'}
                                                    />
                                                </div>
                                                <div>
                                                    <Label className="text-[10px]">
                                                        Max Tebus / Transaksi (opsional)
                                                    </Label>
                                                    <Input
                                                        type="number"
                                                        min="1"
                                                        step="1"
                                                        value={form.max_tebus_per_transaction}
                                                        onChange={(e) =>
                                                            setForm({
                                                                ...form,
                                                                max_tebus_per_transaction: e.target.value,
                                                            })
                                                        }
                                                        placeholder="kosong = unlimited"
                                                    />
                                                </div>
                                            </div>

                                            <p className="text-[10px] text-amber-700 mt-2">
                                                ⓘ Diskon dihitung otomatis = qty tebus × (harga normal − harga tebus).
                                                Field "Nilai" & "Cap" di atas TIDAK dipakai untuk tipe ini.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {form.type === 'voucher' && (
                                    <div className="border-t pt-3 space-y-2">
                                        <Label htmlFor="vcode" className="text-sm font-semibold">
                                            Kode Voucher *
                                        </Label>
                                        <Input id="vcode"
                                            value={form.voucher_code}
                                            onChange={(e) => setForm({
                                                ...form,
                                                voucher_code: e.target.value
                                                    .toUpperCase()
                                                    .replace(/[^A-Z0-9_\-]/g, ''),
                                            })}
                                            placeholder="DISKON20"
                                            maxLength={32}
                                            className="font-mono uppercase tracking-wider"
                                            required={form.type === 'voucher'}
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            A-Z, 0-9, underscore, dash. Auto UPPERCASE. Pelanggan kasih kode
                                            ini ke kasir saat transaksi. Kode harus <strong>unik</strong> antar
                                            semua promo.
                                            {' '}Set kuota = 1 di tab "Kuota" untuk single-use voucher.
                                        </p>
                                    </div>
                                )}

                                <div className="border-t pt-3 space-y-2">
                                    <label className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={form.is_active}
                                            onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                                        Promo aktif
                                    </label>
                                    <label className="flex items-start gap-2 text-sm">
                                        <input type="checkbox" checked={form.is_stackable}
                                            onChange={(e) => setForm({ ...form, is_stackable: e.target.checked })}
                                            className="mt-1" />
                                        <div>
                                            <div>Boleh digabung dgn promo lain (stackable)</div>
                                            <div className="text-xs text-muted-foreground">
                                                Default <strong>OFF</strong> = eksklusif. Kalau pelanggan dapet
                                                beberapa promo eksklusif sekaligus, kasir auto-pilih yg
                                                <strong> diskonnya terbesar</strong> (tie-break: promo lebih lama
                                                menang). <br />
                                                <strong>ON</strong> = promo ini selalu numpuk di atas eksklusif terpilih.
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            </TabsContent>

                            <TabsContent value="periode" className="space-y-3">
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label htmlFor="sa">Mulai *</Label>
                                        <Input id="sa" type="datetime-local" required
                                            value={form.starts_at}
                                            onChange={(e) => setForm({ ...form, starts_at: e.target.value })} />
                                    </div>
                                    <div>
                                        <Label htmlFor="ea">Berakhir *</Label>
                                        <Input id="ea" type="datetime-local" required
                                            value={form.ends_at}
                                            onChange={(e) => setForm({ ...form, ends_at: e.target.value })} />
                                    </div>
                                </div>
                                <div className="border-t pt-3">
                                    <label className="mb-2 flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={form.use_days}
                                            onChange={(e) => setForm({ ...form, use_days: e.target.checked })} />
                                        Hanya hari tertentu (mis. Sabtu-Minggu)
                                    </label>
                                    {form.use_days && (
                                        <div className="flex gap-1">
                                            {DAYS.map((d) => (
                                                <button type="button" key={d.value}
                                                    onClick={() => toggleDay(d.value)}
                                                    className={`flex-1 rounded-md border p-2 text-sm ${
                                                        form.days_of_week.includes(d.value)
                                                            ? 'border-primary bg-primary/10 ring-2 ring-primary'
                                                            : 'border-input hover:bg-muted/50'
                                                    }`}>
                                                    {d.label}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                <div className="border-t pt-3">
                                    <label className="mb-2 flex items-center gap-2 text-sm">
                                        <input type="checkbox" checked={form.use_hours}
                                            onChange={(e) => setForm({ ...form, use_hours: e.target.checked })} />
                                        Hanya jam tertentu (happy hour)
                                    </label>
                                    {form.use_hours && (
                                        <div className="grid grid-cols-2 gap-3">
                                            <div>
                                                <Label htmlFor="ts">Mulai jam</Label>
                                                <Input id="ts" type="time" value={form.time_start}
                                                    onChange={(e) => setForm({ ...form, time_start: e.target.value })} />
                                            </div>
                                            <div>
                                                <Label htmlFor="te">Sampai jam</Label>
                                                <Input id="te" type="time" value={form.time_end}
                                                    onChange={(e) => setForm({ ...form, time_end: e.target.value })} />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </TabsContent>

                            <TabsContent value="cabang" className="space-y-3">
                                <div className="flex gap-2">
                                    {[false, true].map((spec) => (
                                        <button type="button" key={String(spec)}
                                            onClick={() => setForm({ ...form, use_specific_warehouses: spec })}
                                            className={`flex-1 rounded-md border p-3 text-sm font-medium ${
                                                form.use_specific_warehouses === spec
                                                    ? 'border-primary bg-primary/10 ring-2 ring-primary'
                                                    : 'border-input hover:bg-muted/50'
                                            }`}>
                                            {spec ? 'Cabang Tertentu' : 'Semua Cabang'}
                                        </button>
                                    ))}
                                </div>
                                {form.use_specific_warehouses && (
                                    <div className="space-y-1 border-t pt-3">
                                        {warehouses.map((w) => (
                                            <label key={w.id} className="flex items-center gap-2 text-sm">
                                                <input type="checkbox"
                                                    checked={form.warehouse_ids.includes(w.id)}
                                                    onChange={() => toggleWarehouse(w.id)} />
                                                {w.name} <span className="text-xs text-muted-foreground">({w.code})</span>
                                            </label>
                                        ))}
                                    </div>
                                )}
                            </TabsContent>

                            <TabsContent value="akun" className="space-y-3">
                                <div>
                                    <Label htmlFor="coa">COA Diskon</Label>
                                    <select id="coa" value={form.discount_coa_id}
                                        onChange={(e) => setForm({ ...form, discount_coa_id: e.target.value })}
                                        className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                        <option value="">— default (4199 Diskon Penjualan) —</option>
                                        {coas.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.code} · {c.name} ({c.type})
                                            </option>
                                        ))}
                                    </select>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Saat transaksi, jumlah diskon di-debit ke COA ini. Default = 4199 Diskon Penjualan
                                        (contra-revenue, existing). Bikin sub-account spesifik (mis. 4198 Diskon Promo) lewat
                                        Master COA kalau owner mau pisahkan reporting.
                                    </p>
                                </div>
                            </TabsContent>

                            <TabsContent value="syarat" className="space-y-3">
                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <Label htmlFor="mp">Min. Belanja (Rp)</Label>
                                        <Input id="mp" type="number" step="0.01" min="0"
                                            value={form.min_purchase}
                                            onChange={(e) => setForm({ ...form, min_purchase: e.target.value })} />
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            0 = tanpa syarat
                                        </p>
                                    </div>
                                    <div>
                                        <Label htmlFor="mq">Min. Qty</Label>
                                        <Input id="mq" type="number" step="1" min="0"
                                            value={form.min_qty}
                                            onChange={(e) => setForm({ ...form, min_qty: e.target.value })} />
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            0 = tanpa syarat
                                        </p>
                                    </div>
                                </div>
                            </TabsContent>

                            <TabsContent value="kuota" className="space-y-3">
                                <div>
                                    <Label htmlFor="qt">Kuota Total Pemakaian</Label>
                                    <Input id="qt" type="number" step="1" min="1"
                                        placeholder="kosong = unlimited"
                                        value={form.quota_total}
                                        onChange={(e) => setForm({ ...form, quota_total: e.target.value })} />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Kosong = unlimited. Counter naik tiap promo kepakai di transaksi sukses.
                                        Habis = promo nonaktif otomatis (tidak muncul di POS).
                                    </p>
                                </div>
                            </TabsContent>
                        </Tabs>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)} disabled={submitting}>
                                Batal
                            </Button>
                            <Button type="submit" disabled={submitting}>
                                {submitting ? 'Menyimpan…' : isEdit ? 'Simpan Perubahan' : 'Tambah Promo'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
