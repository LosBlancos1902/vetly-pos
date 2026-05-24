import { useMemo, useState, type FormEvent } from 'react';
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

type ColorSlug = 'muted' | 'info' | 'success' | 'destructive' | 'warning' | 'secondary';

interface Category {
    id: number;
    name: string;
    parent_id: number | null;
    color: ColorSlug;
    icon: string | null;
    is_active: boolean;
    customer_count: number;
    parent_name: string | null;
    children_count: number;
}

interface ParentOption {
    id: number;
    name: string;
    parent_id: number | null;
}

interface Paginated {
    data: Category[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    categories: Paginated;
    parentOptions: ParentOption[];
    colors: ColorSlug[];
    filters: { search?: string };
}

interface FormState {
    id?: number;
    name: string;
    parent_id: string;
    color: ColorSlug;
    icon: string;
    is_active: boolean;
}

const EMPTY_FORM: FormState = {
    name: '', parent_id: '', color: 'muted', icon: '', is_active: true,
};

export default function CustomerCategories({ categories, parentOptions, colors, filters }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [search, setSearch] = useState(filters.search ?? '');
    const [submitting, setSubmitting] = useState(false);
    const isEdit = form.id !== undefined;

    // Untuk dropdown parent: exclude self + descendants
    const validParents = useMemo(() => {
        if (! isEdit) return parentOptions;
        const blocked = new Set<number>([form.id!]);
        let frontier = [form.id!];
        while (frontier.length) {
            const next: number[] = [];
            parentOptions.forEach((p) => {
                if (p.parent_id && frontier.includes(p.parent_id)) {
                    blocked.add(p.id);
                    next.push(p.id);
                }
            });
            frontier = next;
        }

        return parentOptions.filter((p) => ! blocked.has(p.id));
    }, [parentOptions, form.id, isEdit]);

    function startCreate() {
        setForm(EMPTY_FORM);
        setOpen(true);
    }

    function startEdit(c: Category) {
        setForm({
            id: c.id,
            name: c.name,
            parent_id: c.parent_id ? String(c.parent_id) : '',
            color: c.color,
            icon: c.icon ?? '',
            is_active: c.is_active,
        });
        setOpen(true);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        setSubmitting(true);

        const payload = {
            name: form.name.trim(),
            parent_id: form.parent_id ? Number(form.parent_id) : null,
            color: form.color,
            icon: form.icon.trim() || null,
            is_active: form.is_active,
        };

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(isEdit ? 'Kategori diperbarui' : 'Kategori ditambahkan');
                setOpen(false);
                setForm(EMPTY_FORM);
            },
            onError: (errs: Record<string, string>) =>
                toast.error(Object.values(errs)[0] ?? 'Gagal'),
            onFinish: () => setSubmitting(false),
        };

        if (isEdit) {
            router.put(route('master.customer_categories.update', form.id!), payload, opts);
        } else {
            router.post(route('master.customer_categories.store'), payload, opts);
        }
    }

    function destroy(c: Category) {
        const msg = c.customer_count > 0
            ? `Kategori "${c.name}" dipakai ${c.customer_count} pelanggan. Klik OK untuk NONAKTIFKAN (jaga histori).`
            : `Hapus kategori "${c.name}"?`;
        if (! confirm(msg)) return;
        router.delete(route('master.customer_categories.destroy', c.id), {
            preserveScroll: true,
            onSuccess: () => toast.success('Kategori diproses'),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    function doSearch(e: FormEvent) {
        e.preventDefault();
        router.get(route('master.customer_categories.index'), { search },
            { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Master Kategori Pelanggan</h2>}>
            <Head title="Kategori Pelanggan" />

            <div className="mx-auto max-w-5xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <form onSubmit={doSearch} className="flex gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama kategori"
                            className="w-72"
                        />
                        <Button type="submit" variant="outline">Cari</Button>
                    </form>
                    <Button type="button" onClick={startCreate}>+ Tambah Kategori</Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kategori</TableHead>
                                    <TableHead>Parent</TableHead>
                                    <TableHead className="text-right">Sub-kategori</TableHead>
                                    <TableHead className="text-right">Pelanggan</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {categories.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                            Belum ada kategori pelanggan.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {categories.data.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell>
                                            <Badge variant={c.color} className="gap-1">
                                                {c.icon && <span>{c.icon}</span>}
                                                <span>{c.name}</span>
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {c.parent_name ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {c.children_count > 0 ? c.children_count : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {c.customer_count > 0 ? c.customer_count : '—'}
                                        </TableCell>
                                        <TableCell>
                                            {c.is_active
                                                ? <Badge variant="success">aktif</Badge>
                                                : <Badge variant="muted">nonaktif</Badge>}
                                        </TableCell>
                                        <TableCell className="text-right space-x-1">
                                            <Button size="sm" variant="ghost" onClick={() => startEdit(c)}>
                                                Edit
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => destroy(c)}>
                                                {c.customer_count > 0 ? 'Nonaktifkan' : 'Hapus'}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {categories.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>{categories.from}–{categories.to} dari {categories.total}</div>
                        <div className="flex gap-1">
                            {categories.links.map((l, i) => (
                                <Button key={i} variant={l.active ? 'default' : 'outline'} size="sm"
                                    disabled={! l.url}
                                    onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true })}
                                    dangerouslySetInnerHTML={{ __html: l.label }} />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <Dialog open={open} onOpenChange={(o) => ! submitting && setOpen(o)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{isEdit ? 'Edit Kategori Pelanggan' : 'Tambah Kategori Pelanggan'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid grid-cols-3 gap-3">
                            <div className="col-span-2">
                                <Label htmlFor="cc-name">Nama *</Label>
                                <Input id="cc-name" value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    required maxLength={120} autoFocus
                                    placeholder="mis. Member, VIP, Reguler, Corporate" />
                            </div>
                            <div>
                                <Label htmlFor="cc-icon">Icon</Label>
                                <Input id="cc-icon" value={form.icon}
                                    onChange={(e) => setForm({ ...form, icon: e.target.value })}
                                    maxLength={8} placeholder="⭐"
                                    className="text-center text-lg" />
                            </div>
                        </div>

                        <div>
                            <Label htmlFor="cc-parent">Parent (opsional)</Label>
                            <select id="cc-parent"
                                value={form.parent_id}
                                onChange={(e) => setForm({ ...form, parent_id: e.target.value })}
                                className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                <option value="">— root —</option>
                                {validParents.map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </select>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Kosongkan untuk root. Pilih parent untuk bikin sub-kategori (mis. Member &gt; VIP).
                            </p>
                        </div>

                        <div>
                            <Label>Warna Badge</Label>
                            <div className="grid grid-cols-3 gap-2">
                                {colors.map((c) => (
                                    <button type="button" key={c}
                                        onClick={() => setForm({ ...form, color: c })}
                                        className={`rounded-md border p-2 text-center text-xs ${
                                            form.color === c
                                                ? 'border-primary ring-2 ring-primary'
                                                : 'border-input hover:bg-muted/50'
                                        }`}>
                                        <Badge variant={c}>
                                            {form.icon ? <span className="mr-1">{form.icon}</span> : null}
                                            {c}
                                        </Badge>
                                    </button>
                                ))}
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Preview badge: {form.name && (
                                    <Badge variant={form.color} className="ml-1">
                                        {form.icon && <span className="mr-1">{form.icon}</span>}
                                        {form.name}
                                    </Badge>
                                )}
                            </p>
                        </div>

                        <label className="flex items-center gap-2 text-sm">
                            <input type="checkbox"
                                checked={form.is_active}
                                onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                            Aktif
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)} disabled={submitting}>
                                Batal
                            </Button>
                            <Button type="submit" disabled={submitting}>
                                {submitting ? 'Menyimpan…' : isEdit ? 'Simpan Perubahan' : 'Tambah Kategori'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
