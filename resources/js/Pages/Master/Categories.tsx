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

interface Category {
    id: number;
    name: string;
    parent_id: number | null;
    is_active: boolean;
    product_count: number;
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
    filters: { search?: string };
}

interface FormState {
    id?: number;
    name: string;
    parent_id: string; // '' = root
    is_active: boolean;
}

const EMPTY_FORM: FormState = { name: '', parent_id: '', is_active: true };

export default function Categories({ categories, parentOptions, filters }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [search, setSearch] = useState(filters.search ?? '');
    const [submitting, setSubmitting] = useState(false);
    const isEdit = form.id !== undefined;

    // Untuk dropdown parent: exclude self + descendants supaya tidak bikin loop.
    const validParents = useMemo(() => {
        if (! isEdit) return parentOptions;
        const blocked = new Set<number>([form.id!]);
        // BFS descendants
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
            router.put(route('master.categories.update', form.id!), payload, opts);
        } else {
            router.post(route('master.categories.store'), payload, opts);
        }
    }

    function destroy(c: Category) {
        const msg = c.product_count > 0
            ? `Kategori "${c.name}" dipakai ${c.product_count} produk. Klik OK untuk NONAKTIFKAN (jaga histori).`
            : `Hapus kategori "${c.name}"?`;
        if (! confirm(msg)) return;
        router.delete(route('master.categories.destroy', c.id), {
            preserveScroll: true,
            onSuccess: () => toast.success('Kategori diproses'),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    function doSearch(e: FormEvent) {
        e.preventDefault();
        router.get(route('master.categories.index'), { search },
            { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Master Kategori</h2>}>
            <Head title="Master Kategori" />

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
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Parent</TableHead>
                                    <TableHead className="text-right">Sub-kategori</TableHead>
                                    <TableHead className="text-right">Produk</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {categories.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                            Belum ada kategori.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {categories.data.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell className="font-medium">{c.name}</TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {c.parent_name ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {c.children_count > 0 ? c.children_count : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {c.product_count > 0 ? c.product_count : '—'}
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
                                                {c.product_count > 0 ? 'Nonaktifkan' : 'Hapus'}
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

            <Dialog open={open} onOpenChange={(o) => ! submitting && setOpen(o)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{isEdit ? 'Edit Kategori' : 'Tambah Kategori'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div>
                            <Label htmlFor="c-name">Nama *</Label>
                            <Input id="c-name" value={form.name}
                                onChange={(e) => setForm({ ...form, name: e.target.value })}
                                required maxLength={120} autoFocus />
                        </div>
                        <div>
                            <Label htmlFor="c-parent">Parent (opsional)</Label>
                            <select
                                id="c-parent"
                                value={form.parent_id}
                                onChange={(e) => setForm({ ...form, parent_id: e.target.value })}
                                className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base">
                                <option value="">— root —</option>
                                {validParents.map((p) => (
                                    <option key={p.id} value={p.id}>{p.name}</option>
                                ))}
                            </select>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Kosongkan untuk kategori root. Pilih parent untuk bikin sub-kategori.
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
