import { useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
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
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';

interface UserOption {
    id: number;
    name: string;
}
interface SubjectType {
    value: string;
    label: string;
}
interface Causer {
    id: number;
    name: string;
}
interface ActivityRow {
    id: number;
    log_name: string | null;
    event: string | null;
    description: string | null;
    subject_type: string | null;
    subject_id: number | null;
    causer: Causer | null;
    properties: Record<string, unknown> | null;
    created_at: string | null;
}
interface PageLink {
    url: string | null;
    label: string;
    active: boolean;
}
interface Paginated {
    data: ActivityRow[];
    links: PageLink[];
    from: number | null;
    to: number | null;
    total: number;
}
interface Filters {
    causer_id?: string;
    event?: string;
    subject_type?: string;
    log_name?: string;
    date_from?: string;
    date_to?: string;
}
interface Props {
    activities: Paginated;
    filters: Filters;
    users: UserOption[];
    subjectTypes: SubjectType[];
    events: string[];
    logNames: string[];
}

const EVENT_VARIANT: Record<string, string> = {
    created: 'success',
    updated: 'info',
    deleted: 'destructive',
};

const EVENT_LABEL: Record<string, string> = {
    created: 'Dibuat',
    updated: 'Diubah',
    deleted: 'Dihapus',
    role_assigned: 'Atur Role',
    permissions_synced: 'Atur Permission',
};

function eventLabel(ev: string | null, fallback: string | null): string {
    if (ev && EVENT_LABEL[ev]) return EVENT_LABEL[ev];
    return ev ?? fallback ?? '-';
}

/* ─────────────────────────────────────────────────────────────────────
 * Humanisasi properties — label field + format nilai (Bahasa Indonesia).
 * Murni presentasi di FE; data dari spatie dikirim apa adanya.
 * ──────────────────────────────────────────────────────────────────── */

// Label field → Bahasa Indonesia awam. Fallback: title-case dari nama field.
const FIELD_LABELS: Record<string, string> = {
    name: 'Nama',
    code: 'Kode',
    sku: 'SKU',
    barcode: 'Barcode',
    price: 'Harga',
    cost_avg: 'HPP',
    cost_snapshot: 'HPP',
    cost: 'HPP',
    min_stock: 'Stok Minimum',
    max_stock: 'Stok Maksimum',
    stock: 'Stok',
    qty: 'Qty',
    quantity: 'Qty',
    description: 'Deskripsi',
    is_active: 'Status Aktif',
    is_default: 'Default',
    is_stackable: 'Bisa Ditumpuk',
    is_sellable_directly: 'Bisa Dijual Langsung',
    requires_prescription: 'Wajib Resep',
    has_expiry: 'Ada Kadaluarsa',
    has_batch: 'Ada Batch',
    allow_stock_minus: 'Boleh Stok Minus',
    type: 'Jenis',
    status: 'Status',
    payment_status: 'Status Pembayaran',
    notes: 'Catatan',
    email: 'Email',
    phone: 'Telepon',
    address: 'Alamat',
    total: 'Total',
    subtotal: 'Subtotal',
    discount_amount: 'Diskon',
    discount_value: 'Nilai Diskon',
    max_discount_amount: 'Maks. Diskon',
    min_purchase: 'Min. Pembelian',
    min_qty: 'Min. Qty',
    tax_amount: 'Pajak',
    quota_total: 'Kuota Total',
    quota_used: 'Kuota Terpakai',
    points: 'Poin',
    total_spent: 'Total Belanja',
    birthday: 'Tgl Lahir',
    starts_at: 'Mulai',
    ends_at: 'Berakhir',
    approved_at: 'Disetujui',
    completed_at: 'Selesai',
    cancelled_at: 'Dibatalkan',
    shipped_at: 'Dikirim',
    received_at: 'Diterima',
    opname_date: 'Tgl Opname',
    brand_name: 'Nama Brand',
    footer_text: 'Footer Struk',
    footer_override: 'Footer Cabang',
    npwp: 'NPWP',
    license_no: 'No. Izin',
    warehouse_type: 'Jenis Gudang',
    // Relasi (FK) — value formatter menambah "(ID)" otomatis
    category_id: 'Kategori',
    brand_id: 'Merek',
    base_unit_id: 'Satuan Dasar',
    unit_id: 'Satuan',
    warehouse_id: 'Gudang',
    source_warehouse_id: 'Gudang Asal',
    dest_warehouse_id: 'Gudang Tujuan',
    supplier_id: 'Supplier',
    customer_id: 'Pelanggan',
    product_id: 'Produk',
    discount_coa_id: 'Akun Diskon',
    // Log manual role/permission
    old: 'Sebelum',
    new: 'Sesudah',
};

function titleCase(field: string): string {
    return field
        .replace(/_id$/, '')
        .split('_')
        .map((w) => (w ? w.charAt(0).toUpperCase() + w.slice(1) : w))
        .join(' ');
}

function labelFor(field: string): string {
    return FIELD_LABELS[field] ?? titleCase(field);
}

const MONEY_RE =
    /(price|cost|hpp|harga|total|subtotal|discount|amount|tax|paid|payable|balance|spent|purchase)/i;
const QTY_RE = /(qty|quantity|stock|stok|points|poin|quota|count|jumlah)/i;
const DATE_RE =
    /(_at$|date|birthday|starts|ends|shipped|received|completed|cancelled|approved|voided)/i;
const BOOL_PREFIX_RE = /^(is_|has_|requires_|allow_|can_)/;

const idr = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });

function formatValue(field: string, value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';

    // Array (mis. daftar role/permission di log manual)
    if (Array.isArray(value)) {
        return value.length ? value.map((v) => String(v)).join(', ') : '—';
    }

    // Boolean
    if (typeof value === 'boolean' || BOOL_PREFIX_RE.test(field)) {
        const truthy =
            value === true || value === 1 || value === '1' || value === 'true';
        return truthy ? 'Ya' : 'Tidak';
    }

    // Foreign key → tampilkan ID + penanda (lookup nama butuh backend)
    if (/_id$/.test(field)) {
        return `${value} (ID)`;
    }

    // Tanggal → format Indonesia
    if (DATE_RE.test(field)) {
        const d = new Date(String(value));
        if (!isNaN(d.getTime())) {
            return d.toLocaleString('id-ID', {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        }
        return String(value);
    }

    // Qty / angka — buang trailing zero (10.00 → 10, 10.50 → 10.5)
    if (QTY_RE.test(field)) {
        const n = Number(value);
        return isNaN(n) ? String(value) : String(n);
    }

    // Uang → Rupiah (0 desimal)
    if (MONEY_RE.test(field)) {
        const n = Number(value);
        return isNaN(n) ? String(value) : `Rp ${idr.format(n)}`;
    }

    return String(value);
}

// Field penting yg ditonjolkan saat create/delete (hindari dump semua field).
const IMPORTANT_FIELDS = [
    'name',
    'code',
    'sku',
    'type',
    'price',
    'cost_avg',
    'category_id',
    'brand_id',
    'warehouse_id',
    'supplier_id',
    'customer_id',
    'email',
    'phone',
    'status',
    'is_active',
    'brand_name',
    'discount_value',
    'total',
];

function pickFields(obj: Record<string, unknown>): [string, unknown][] {
    const entries = Object.entries(obj).filter(
        ([, v]) => v !== null && v !== '' && v !== undefined,
    );
    const important = entries.filter(([k]) => IMPORTANT_FIELDS.includes(k));
    return important.length ? important : entries.slice(0, 6);
}

interface DiffRow {
    field: string;
    before: string | null; // null = mode single-value (create/delete)
    after: string;
}

function buildRows(detail: ActivityRow): { heading: string | null; rows: DiffRow[] } {
    const props = (detail.properties ?? {}) as Record<string, unknown>;
    const attrs = props.attributes as Record<string, unknown> | undefined;
    const old = props.old as Record<string, unknown> | undefined;
    const ev = detail.event;

    // UPDATE: ada attributes + old → diff per field
    if (attrs && old) {
        const rows = Object.keys(attrs).map((k) => ({
            field: k,
            before: formatValue(k, old[k]),
            after: formatValue(k, attrs[k]),
        }));
        return { heading: null, rows };
    }

    // CREATE / DELETE: hanya attributes (atau old utk delete) → daftar nilai
    if (attrs || (ev === 'deleted' && old)) {
        const source = attrs ?? old ?? {};
        const heading =
            ev === 'created'
                ? 'Data dibuat'
                : ev === 'deleted'
                  ? 'Data dihapus'
                  : 'Data';
        const rows = pickFields(source).map(([k, v]) => ({
            field: k,
            before: null,
            after: formatValue(k, v),
        }));
        return { heading, rows };
    }

    // LOG MANUAL (role_assigned / permissions_synced): old → new (bisa array)
    if (props.old !== undefined || props.new !== undefined) {
        return {
            heading: null,
            rows: [
                {
                    field: 'old',
                    before: null,
                    after: '', // disusun khusus di renderer manual
                },
            ],
        };
    }

    return { heading: null, rows: [] };
}

function fmtDate(iso: string | null): string {
    if (!iso) return '-';
    const d = new Date(iso);
    return d.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** Ringkas perubahan jadi "field: lama → baru" untuk tabel utama. */
function summarize(props: Record<string, unknown> | null): string {
    if (!props) return '-';
    const attrs = (props.attributes ?? null) as Record<string, unknown> | null;
    const old = (props.old ?? null) as Record<string, unknown> | null;
    if (!attrs) {
        // Log manual (role_assigned / permissions_synced) atau create tanpa diff.
        if (old || props.new !== undefined) {
            const b = Array.isArray(old) ? old.join(', ') : '';
            const a = Array.isArray(props.new) ? (props.new as unknown[]).join(', ') : '';
            return `${b || '—'} → ${a || '—'}`;
        }
        return Object.keys(props).join(', ') || '-';
    }
    const keys = Object.keys(attrs);
    if (keys.length === 0) return '-';
    return (
        keys
            .slice(0, 4)
            .map((k) => {
                const to = String(attrs[k] ?? '∅');
                if (old && k in old) return `${k}: ${String(old[k] ?? '∅')} → ${to}`;
                return `${k}: ${to}`;
            })
            .join('; ') + (keys.length > 4 ? ` (+${keys.length - 4} lainnya)` : '')
    );
}

export default function ActivityLog({
    activities,
    filters,
    users,
    subjectTypes,
    events,
    logNames,
}: Props) {
    const [form, setForm] = useState<Filters>({
        causer_id: filters.causer_id ?? '',
        event: filters.event ?? '',
        subject_type: filters.subject_type ?? '',
        log_name: filters.log_name ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });
    const [detail, setDetail] = useState<ActivityRow | null>(null);
    const [showRaw, setShowRaw] = useState(false);

    function openDetail(a: ActivityRow) {
        setShowRaw(false);
        setDetail(a);
    }

    function applyFilters(e: FormEvent) {
        e.preventDefault();
        const query: Record<string, string> = {};
        Object.entries(form).forEach(([k, v]) => {
            if (v) query[k] = v;
        });
        router.get(route('settings.audit.index'), query, {
            preserveScroll: true,
            preserveState: true,
        });
    }

    function reset() {
        setForm({
            causer_id: '',
            event: '',
            subject_type: '',
            log_name: '',
            date_from: '',
            date_to: '',
        });
        router.get(route('settings.audit.index'), {}, { preserveScroll: true });
    }

    const selectCls =
        'flex h-11 w-full rounded-md border border-input bg-background px-3 text-base';

    // Data terstruktur utk dialog detail.
    const detailProps = (detail?.properties ?? {}) as Record<string, unknown>;
    const isManual =
        detail !== null &&
        detailProps.attributes === undefined &&
        (detailProps.old !== undefined || detailProps.new !== undefined);
    const built = detail ? buildRows(detail) : { heading: null, rows: [] };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Riwayat Aktivitas</h2>}
        >
            <Head title="Riwayat Aktivitas" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <Card>
                    <CardContent className="p-4">
                        <form
                            onSubmit={applyFilters}
                            className="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:grid-cols-6"
                        >
                            <div>
                                <Label htmlFor="f-user">Pengguna</Label>
                                <select
                                    id="f-user"
                                    className={selectCls}
                                    value={form.causer_id}
                                    onChange={(e) =>
                                        setForm({ ...form, causer_id: e.target.value })
                                    }
                                >
                                    <option value="">— Semua —</option>
                                    {users.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-event">Aksi</Label>
                                <select
                                    id="f-event"
                                    className={selectCls}
                                    value={form.event}
                                    onChange={(e) =>
                                        setForm({ ...form, event: e.target.value })
                                    }
                                >
                                    <option value="">— Semua —</option>
                                    {events.map((ev) => (
                                        <option key={ev} value={ev}>
                                            {eventLabel(ev, ev)}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-subject">Jenis Data</Label>
                                <select
                                    id="f-subject"
                                    className={selectCls}
                                    value={form.subject_type}
                                    onChange={(e) =>
                                        setForm({ ...form, subject_type: e.target.value })
                                    }
                                >
                                    <option value="">— Semua —</option>
                                    {subjectTypes.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-log">Kategori</Label>
                                <select
                                    id="f-log"
                                    className={selectCls}
                                    value={form.log_name}
                                    onChange={(e) =>
                                        setForm({ ...form, log_name: e.target.value })
                                    }
                                >
                                    <option value="">— Semua —</option>
                                    {logNames.map((l) => (
                                        <option key={l} value={l}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <Label htmlFor="f-from">Dari Tanggal</Label>
                                <Input
                                    id="f-from"
                                    type="date"
                                    value={form.date_from}
                                    onChange={(e) =>
                                        setForm({ ...form, date_from: e.target.value })
                                    }
                                />
                            </div>
                            <div>
                                <Label htmlFor="f-to">Sampai Tanggal</Label>
                                <Input
                                    id="f-to"
                                    type="date"
                                    value={form.date_to}
                                    onChange={(e) =>
                                        setForm({ ...form, date_to: e.target.value })
                                    }
                                />
                            </div>
                            <div className="flex items-end gap-2 sm:col-span-3 lg:col-span-6">
                                <Button type="submit">Terapkan Filter</Button>
                                <Button type="button" variant="ghost" onClick={reset}>
                                    Reset
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Waktu</TableHead>
                                    <TableHead>Pengguna</TableHead>
                                    <TableHead>Aksi</TableHead>
                                    <TableHead>Data</TableHead>
                                    <TableHead>Ringkasan Perubahan</TableHead>
                                    <TableHead className="text-right">Detail</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {activities.data.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            Belum ada aktivitas tercatat.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {activities.data.map((a) => (
                                    <TableRow key={a.id}>
                                        <TableCell className="whitespace-nowrap text-sm">
                                            {fmtDate(a.created_at)}
                                        </TableCell>
                                        <TableCell>{a.causer?.name ?? '—'}</TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    (EVENT_VARIANT[a.event ?? ''] ??
                                                        'secondary') as never
                                                }
                                            >
                                                {eventLabel(a.event, a.description)}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {a.subject_type ?? '-'}
                                            {a.subject_id ? ` #${a.subject_id}` : ''}
                                            {a.log_name && (
                                                <Badge
                                                    variant="muted"
                                                    className="ml-1"
                                                >
                                                    {a.log_name}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="max-w-md truncate text-sm text-muted-foreground">
                                            {summarize(a.properties)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => openDetail(a)}
                                            >
                                                Lihat
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Pagination */}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-muted-foreground">
                        Menampilkan {activities.from ?? 0}–{activities.to ?? 0} dari{' '}
                        {activities.total}
                    </p>
                    <div className="flex flex-wrap gap-1">
                        {activities.links.map((l, i) => (
                            <Link
                                key={i}
                                href={l.url ?? '#'}
                                preserveScroll
                                className={
                                    'rounded px-3 py-1 text-sm ' +
                                    (l.active
                                        ? 'bg-indigo-600 text-white'
                                        : l.url
                                          ? 'bg-white text-gray-700 hover:bg-gray-100'
                                          : 'cursor-default text-gray-300')
                                }
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                </div>
            </div>

            {/* Detail dialog: tampilan manusiawi per-field */}
            <Dialog open={!!detail} onOpenChange={(o) => !o && setDetail(null)}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Detail Aktivitas {detail ? `#${detail.id}` : ''}
                        </DialogTitle>
                    </DialogHeader>
                    {detail && (
                        <div className="space-y-4 text-sm">
                            {/* Header ringkas */}
                            <div className="grid grid-cols-2 gap-2 rounded-md bg-gray-50 p-3">
                                <div>
                                    <span className="text-muted-foreground">Waktu:</span>{' '}
                                    {fmtDate(detail.created_at)}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Pengguna:
                                    </span>{' '}
                                    {detail.causer?.name ?? '—'}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Aksi:</span>{' '}
                                    <Badge
                                        variant={
                                            (EVENT_VARIANT[detail.event ?? ''] ??
                                                'secondary') as never
                                        }
                                    >
                                        {eventLabel(detail.event, detail.description)}
                                    </Badge>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Data:</span>{' '}
                                    {detail.subject_type ?? '-'}
                                    {detail.subject_id ? ` #${detail.subject_id}` : ''}
                                </div>
                            </div>

                            {/* Perubahan manusiawi */}
                            <div>
                                {built.heading && (
                                    <p className="mb-2 font-medium">{built.heading}</p>
                                )}

                                {isManual ? (
                                    <table className="w-full border-collapse text-sm">
                                        <tbody>
                                            <tr className="border-b">
                                                <td className="w-1/3 py-2 pr-2 text-muted-foreground">
                                                    Sebelum
                                                </td>
                                                <td className="py-2">
                                                    {formatValue(
                                                        'old',
                                                        detailProps.old,
                                                    )}
                                                </td>
                                            </tr>
                                            <tr>
                                                <td className="w-1/3 py-2 pr-2 text-muted-foreground">
                                                    Sesudah
                                                </td>
                                                <td className="py-2">
                                                    {formatValue(
                                                        'new',
                                                        detailProps.new,
                                                    )}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                ) : built.rows.length === 0 ? (
                                    <p className="text-muted-foreground">
                                        Tidak ada detail perubahan.
                                    </p>
                                ) : (
                                    <table className="w-full border-collapse text-sm">
                                        <thead>
                                            <tr className="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                                                <th className="py-2 pr-2 font-medium">
                                                    Field
                                                </th>
                                                {built.rows[0].before !== null && (
                                                    <th className="py-2 pr-2 font-medium">
                                                        Sebelum
                                                    </th>
                                                )}
                                                <th className="py-2 font-medium">
                                                    {built.rows[0].before !== null
                                                        ? 'Sesudah'
                                                        : 'Nilai'}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {built.rows.map((r) => (
                                                <tr
                                                    key={r.field}
                                                    className="border-b last:border-0 align-top"
                                                >
                                                    <td className="py-2 pr-2 font-medium">
                                                        {labelFor(r.field)}
                                                    </td>
                                                    {r.before !== null && (
                                                        <td className="py-2 pr-2 text-muted-foreground">
                                                            {r.before}
                                                        </td>
                                                    )}
                                                    <td className="py-2">{r.after}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </div>

                            {/* Raw JSON (collapsible) — untuk developer/owner */}
                            <div>
                                <button
                                    type="button"
                                    onClick={() => setShowRaw((v) => !v)}
                                    className="text-xs text-indigo-600 hover:underline"
                                >
                                    {showRaw
                                        ? '▾ Sembunyikan JSON mentah'
                                        : '▸ Lihat JSON mentah'}
                                </button>
                                {showRaw && (
                                    <pre className="mt-2 max-h-72 overflow-auto rounded bg-gray-900 p-3 text-xs text-gray-100">
                                        {JSON.stringify(
                                            detail.properties ?? {},
                                            null,
                                            2,
                                        )}
                                    </pre>
                                )}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
