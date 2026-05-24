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
import { rupiah } from '@/lib/utils';

interface Customer {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    email: string | null;
    birthday: string | null;
    address: string | null;
    notes: string | null;
    is_active: boolean;
    total_spent: string;
    sales_count: number;
    vetly_customer_id: string | null;
}

interface Paginated {
    data: Customer[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    customers: Paginated;
    filters: { search?: string; status?: 'active' | 'inactive' };
}

interface FormState {
    id?: number;
    name: string;
    phone: string;
    email: string;
    birthday: string;
    address: string;
    notes: string;
    is_active: boolean;
}

const EMPTY_FORM: FormState = {
    name: '', phone: '', email: '', birthday: '', address: '', notes: '', is_active: true,
};

export default function Customers({ customers, filters }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState<'' | 'active' | 'inactive'>(filters.status ?? '');
    const [submitting, setSubmitting] = useState(false);
    const isEdit = form.id !== undefined;

    function startCreate() {
        setForm(EMPTY_FORM);
        setOpen(true);
    }

    function startEdit(c: Customer) {
        setForm({
            id: c.id,
            name: c.name,
            phone: c.phone ?? '',
            email: c.email ?? '',
            birthday: c.birthday ?? '',
            address: c.address ?? '',
            notes: c.notes ?? '',
            is_active: c.is_active,
        });
        setOpen(true);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        setSubmitting(true);

        const payload = {
            name: form.name.trim(),
            phone: form.phone.trim(),
            email: form.email.trim() || null,
            birthday: form.birthday || null,
            address: form.address.trim() || null,
            notes: form.notes.trim() || null,
            is_active: form.is_active,
        };

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(isEdit ? 'Pelanggan diperbarui' : 'Pelanggan ditambahkan');
                setOpen(false);
                setForm(EMPTY_FORM);
            },
            onError: (errs: Record<string, string>) =>
                toast.error(Object.values(errs)[0] ?? 'Gagal'),
            onFinish: () => setSubmitting(false),
        };

        if (isEdit) {
            router.put(route('master.customers.update', form.id!), payload, opts);
        } else {
            router.post(route('master.customers.store'), payload, opts);
        }
    }

    function destroy(c: Customer) {
        const msg = c.sales_count > 0
            ? `Pelanggan "${c.name}" pernah ${c.sales_count} transaksi. Klik OK untuk NONAKTIFKAN (jaga histori).`
            : `Hapus pelanggan "${c.name}"?`;
        if (! confirm(msg)) return;
        router.delete(route('master.customers.destroy', c.id), {
            preserveScroll: true,
            onSuccess: () => toast.success('Pelanggan diproses'),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    function doSearch(e: FormEvent) {
        e.preventDefault();
        router.get(route('master.customers.index'),
            { search: search || undefined, status: status || undefined },
            { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Master Pelanggan</h2>}>
            <Head title="Master Pelanggan" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <form onSubmit={doSearch} className="flex gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama / HP / email / kode"
                            className="w-72"
                        />
                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value as '' | 'active' | 'inactive')}
                            className="flex h-11 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Semua</option>
                            <option value="active">Aktif</option>
                            <option value="inactive">Nonaktif</option>
                        </select>
                        <Button type="submit" variant="outline">Cari</Button>
                    </form>
                    <Button type="button" onClick={startCreate}>+ Tambah Pelanggan</Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>No HP</TableHead>
                                    <TableHead className="text-right">Transaksi</TableHead>
                                    <TableHead className="text-right">Total Belanja</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {customers.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground">
                                            Belum ada pelanggan.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {customers.data.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell className="font-mono text-xs">{c.code}</TableCell>
                                        <TableCell>
                                            <div className="font-medium">{c.name}</div>
                                            {c.email && (
                                                <div className="text-xs text-muted-foreground">{c.email}</div>
                                            )}
                                        </TableCell>
                                        <TableCell className="font-mono text-sm">{c.phone ?? '—'}</TableCell>
                                        <TableCell className="text-right">{c.sales_count}</TableCell>
                                        <TableCell className="text-right">{rupiah(c.total_spent)}</TableCell>
                                        <TableCell>
                                            {c.is_active
                                                ? <Badge variant="success">aktif</Badge>
                                                : <Badge variant="muted">nonaktif</Badge>}
                                            {c.vetly_customer_id && (
                                                <Badge variant="info" className="ml-1 text-[10px]">Klinik</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right space-x-1">
                                            <Button size="sm" variant="ghost" onClick={() => startEdit(c)}>
                                                Edit
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => destroy(c)}>
                                                {c.sales_count > 0 ? 'Nonaktif' : 'Hapus'}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {customers.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>{customers.from}–{customers.to} dari {customers.total}</div>
                        <div className="flex gap-1">
                            {customers.links.map((l, i) => (
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
                        <DialogTitle>{isEdit ? 'Edit Pelanggan' : 'Tambah Pelanggan'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="col-span-2">
                                <Label htmlFor="cu-name">Nama *</Label>
                                <Input id="cu-name" value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    required maxLength={255} autoFocus />
                            </div>
                            <div>
                                <Label htmlFor="cu-phone">No HP * <span className="text-xs text-muted-foreground">(identifier)</span></Label>
                                <Input id="cu-phone" type="tel" value={form.phone}
                                    onChange={(e) => setForm({ ...form, phone: e.target.value })}
                                    required maxLength={32} placeholder="08xxxx" />
                            </div>
                            <div>
                                <Label htmlFor="cu-email">Email</Label>
                                <Input id="cu-email" type="email" value={form.email}
                                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                                    maxLength={255} />
                            </div>
                            <div>
                                <Label htmlFor="cu-bday">Tanggal Lahir</Label>
                                <Input id="cu-bday" type="date" value={form.birthday}
                                    onChange={(e) => setForm({ ...form, birthday: e.target.value })} />
                            </div>
                            <div className="col-span-2">
                                <Label htmlFor="cu-addr">Alamat</Label>
                                <Input id="cu-addr" value={form.address}
                                    onChange={(e) => setForm({ ...form, address: e.target.value })} />
                            </div>
                            <div className="col-span-2">
                                <Label htmlFor="cu-notes">Catatan</Label>
                                <Input id="cu-notes" value={form.notes}
                                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                    placeholder="Preferensi, info khusus, dll" />
                            </div>
                        </div>
                        {isEdit && (
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox"
                                    checked={form.is_active}
                                    onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                                Aktif
                            </label>
                        )}
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)} disabled={submitting}>
                                Batal
                            </Button>
                            <Button type="submit" disabled={submitting}>
                                {submitting ? 'Menyimpan…' : isEdit ? 'Simpan Perubahan' : 'Tambah Pelanggan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
