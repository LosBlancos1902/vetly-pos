import { useState, type FormEvent } from 'react';
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

interface Supplier {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    npwp: string | null;
    payment_term_days: number;
    is_active: boolean;
}

interface PaginatedSuppliers {
    data: Supplier[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    suppliers: PaginatedSuppliers;
    filters: { search?: string };
}

interface FormState {
    id?: number;
    code: string;
    name: string;
    phone: string;
    email: string;
    address: string;
    npwp: string;
    payment_term_days: string;
    is_active: boolean;
}

const EMPTY_FORM: FormState = {
    code: '',
    name: '',
    phone: '',
    email: '',
    address: '',
    npwp: '',
    payment_term_days: '0',
    is_active: true,
};

export default function Suppliers({ suppliers, filters }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [search, setSearch] = useState(filters.search ?? '');
    const isEdit = form.id !== undefined;

    function startCreate() {
        setForm(EMPTY_FORM);
        setOpen(true);
    }

    function startEdit(s: Supplier) {
        setForm({
            id: s.id,
            code: s.code,
            name: s.name,
            phone: s.phone ?? '',
            email: s.email ?? '',
            address: s.address ?? '',
            npwp: s.npwp ?? '',
            payment_term_days: String(s.payment_term_days),
            is_active: s.is_active,
        });
        setOpen(true);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const payload = {
            code: form.code,
            name: form.name,
            phone: form.phone || null,
            email: form.email || null,
            address: form.address || null,
            npwp: form.npwp || null,
            payment_term_days: Number(form.payment_term_days || 0),
            is_active: form.is_active,
        };

        const opts = {
            onSuccess: () => {
                toast.success(isEdit ? 'Supplier diperbarui' : 'Supplier ditambahkan');
                setOpen(false);
            },
            onError: (errs: Record<string, string>) => {
                const first = Object.values(errs)[0];
                if (first) toast.error(first);
            },
            preserveScroll: true,
        };

        if (isEdit) {
            router.put(route('master.suppliers.update', form.id), payload, opts);
        } else {
            router.post(route('master.suppliers.store'), payload, opts);
        }
    }

    function deactivate(s: Supplier) {
        if (!confirm(`Nonaktifkan supplier "${s.name}"?`)) return;
        router.delete(route('master.suppliers.destroy', s.id), {
            onSuccess: () => toast.success(`Supplier "${s.name}" dinonaktifkan`),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal menonaktifkan'),
            preserveScroll: true,
        });
    }

    function activate(s: Supplier) {
        router.put(
            route('master.suppliers.update', s.id),
            {
                code: s.code,
                name: s.name,
                phone: s.phone,
                email: s.email,
                address: s.address,
                npwp: s.npwp,
                payment_term_days: s.payment_term_days,
                is_active: true,
            },
            {
                onSuccess: () => toast.success(`Supplier "${s.name}" diaktifkan`),
                onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal mengaktifkan'),
                preserveScroll: true,
            },
        );
    }

    function submitSearch(e: FormEvent) {
        e.preventDefault();
        router.get(
            route('master.suppliers.index'),
            { search: search || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Supplier</h2>}>
            <Head title="Supplier" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <form onSubmit={submitSearch} className="flex gap-2">
                        <Input
                            placeholder="Cari nama / kode / telp / email"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="w-72"
                        />
                        <Button type="submit" variant="outline">
                            Cari
                        </Button>
                    </form>
                    <Button onClick={startCreate} className="min-h-11">
                        + Tambah Supplier
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Kontak</TableHead>
                                    <TableHead className="text-right">Termin</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {suppliers.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-muted-foreground">
                                            Belum ada supplier.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {suppliers.data.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell className="font-mono text-xs">{s.code}</TableCell>
                                        <TableCell className="font-medium">{s.name}</TableCell>
                                        <TableCell>
                                            <div className="text-sm">{s.phone ?? '-'}</div>
                                            {s.email && (
                                                <div className="text-xs text-muted-foreground">{s.email}</div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {s.payment_term_days === 0
                                                ? 'Cash'
                                                : `${s.payment_term_days} hari`}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={s.is_active ? 'success' : 'destructive'}>
                                                {s.is_active ? 'Aktif' : 'Nonaktif'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button variant="ghost" size="sm" onClick={() => startEdit(s)}>
                                                Edit
                                            </Button>
                                            {s.is_active ? (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-destructive"
                                                    onClick={() => deactivate(s)}
                                                >
                                                    Nonaktifkan
                                                </Button>
                                            ) : (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => activate(s)}
                                                >
                                                    Aktifkan
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {suppliers.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>
                            {suppliers.from}–{suppliers.to} dari {suppliers.total}
                        </div>
                        <div className="flex gap-1">
                            {suppliers.links.map((l, i) => (
                                <Button
                                    key={i}
                                    variant={l.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={!l.url}
                                    onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true })}
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{isEdit ? 'Edit Supplier' : 'Tambah Supplier'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <Label htmlFor="s-code">Kode</Label>
                                <Input
                                    id="s-code"
                                    value={form.code}
                                    onChange={(e) => setForm({ ...form, code: e.target.value })}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="s-name">Nama</Label>
                                <Input
                                    id="s-name"
                                    value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="s-phone">Telp / HP</Label>
                                <Input
                                    id="s-phone"
                                    value={form.phone}
                                    onChange={(e) => setForm({ ...form, phone: e.target.value })}
                                />
                            </div>
                            <div>
                                <Label htmlFor="s-email">Email</Label>
                                <Input
                                    id="s-email"
                                    type="email"
                                    value={form.email}
                                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Label htmlFor="s-address">Alamat</Label>
                                <Input
                                    id="s-address"
                                    value={form.address}
                                    onChange={(e) => setForm({ ...form, address: e.target.value })}
                                />
                            </div>
                            <div>
                                <Label htmlFor="s-npwp">NPWP</Label>
                                <Input
                                    id="s-npwp"
                                    value={form.npwp}
                                    onChange={(e) => setForm({ ...form, npwp: e.target.value })}
                                />
                            </div>
                            <div>
                                <Label htmlFor="s-term">Termin (hari, 0 = cash)</Label>
                                <Input
                                    id="s-term"
                                    type="number"
                                    min="0"
                                    value={form.payment_term_days}
                                    onChange={(e) =>
                                        setForm({ ...form, payment_term_days: e.target.value })
                                    }
                                    required
                                />
                            </div>
                            <div className="flex items-center gap-2 md:col-span-2">
                                <input
                                    id="s-active"
                                    type="checkbox"
                                    checked={form.is_active}
                                    onChange={(e) =>
                                        setForm({ ...form, is_active: e.target.checked })
                                    }
                                />
                                <Label htmlFor="s-active">Aktif</Label>
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
