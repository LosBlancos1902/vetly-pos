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

/** Ringkas perubahan jadi "field: lama → baru" untuk update. */
function summarize(props: Record<string, unknown> | null): string {
    if (!props) return '-';
    const attrs = (props.attributes ?? null) as Record<string, unknown> | null;
    const old = (props.old ?? null) as Record<string, unknown> | null;
    if (!attrs) {
        // Log manual (role_assigned / permissions_synced) atau create tanpa diff.
        return Object.keys(props).join(', ') || '-';
    }
    const keys = Object.keys(attrs);
    if (keys.length === 0) return '-';
    return keys
        .slice(0, 4)
        .map((k) => {
            const to = String(attrs[k] ?? '∅');
            if (old && k in old) return `${k}: ${String(old[k] ?? '∅')} → ${to}`;
            return `${k}: ${to}`;
        })
        .join('; ') + (keys.length > 4 ? ` (+${keys.length - 4} lainnya)` : '');
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
                                            {ev}
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
                                                {a.event ?? a.description ?? '-'}
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
                                                onClick={() => setDetail(a)}
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

            {/* Detail dialog: before/after lengkap */}
            <Dialog open={!!detail} onOpenChange={(o) => !o && setDetail(null)}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Detail Aktivitas {detail ? `#${detail.id}` : ''}
                        </DialogTitle>
                    </DialogHeader>
                    {detail && (
                        <div className="space-y-3 text-sm">
                            <div className="grid grid-cols-2 gap-2">
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
                                    {detail.event ?? detail.description ?? '-'}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">Data:</span>{' '}
                                    {detail.subject_type ?? '-'}
                                    {detail.subject_id ? ` #${detail.subject_id}` : ''}
                                </div>
                            </div>
                            <div>
                                <p className="mb-1 font-medium">Properti (before/after)</p>
                                <pre className="max-h-80 overflow-auto rounded bg-gray-900 p-3 text-xs text-gray-100">
                                    {JSON.stringify(detail.properties ?? {}, null, 2)}
                                </pre>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
