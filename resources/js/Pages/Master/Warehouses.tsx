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
import { formatQty } from '@/lib/utils';

interface Warehouse {
    id: number;
    code: string;
    name: string;
    warehouse_type: string;
    address: string | null;
    is_active: boolean;
    is_default: boolean;
    sku_count: number;
    user_count: number;
}

interface Paginated {
    data: Warehouse[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    warehouses: Paginated;
    warehouseTypes: Record<string, string>;
    filters: { search?: string };
}

interface FormState {
    id?: number;
    code: string;
    name: string;
    warehouse_type: string;
    address: string;
    is_active: boolean;
    is_default: boolean;
}

const EMPTY_FORM: FormState = {
    code: '',
    name: '',
    warehouse_type: 'petshop',
    address: '',
    is_active: true,
    is_default: false,
};

export default function Warehouses({ warehouses, warehouseTypes, filters }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [search, setSearch] = useState(filters.search ?? '');
    const [submitting, setSubmitting] = useState(false);
    const isEdit = form.id !== undefined;

    function startCreate() {
        setForm(EMPTY_FORM);
        setOpen(true);
    }

    function startEdit(w: Warehouse) {
        setForm({
            id: w.id,
            code: w.code,
            name: w.name,
            warehouse_type: w.warehouse_type,
            address: w.address ?? '',
            is_active: w.is_active,
            is_default: w.is_default,
        });
        setOpen(true);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        setSubmitting(true);

        const payload = {
            code: form.code.trim().toUpperCase(),
            name: form.name.trim(),
            warehouse_type: form.warehouse_type,
            address: form.address.trim() || null,
            is_active: form.is_active,
            is_default: form.is_default,
        };

        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(isEdit ? 'Gudang diperbarui' : 'Gudang ditambahkan');
                setOpen(false);
                setForm(EMPTY_FORM);
            },
            onError: (errs: Record<string, string>) =>
                toast.error(Object.values(errs)[0] ?? 'Gagal'),
            onFinish: () => setSubmitting(false),
        };

        if (isEdit) {
            router.put(route('master.warehouses.update', form.id!), payload, opts);
        } else {
            router.post(route('master.warehouses.store'), payload, opts);
        }
    }

    function destroy(w: Warehouse) {
        const blocked = w.sku_count > 0 || w.user_count > 0;
        const msg = blocked
            ? `Gudang "${w.name}" masih punya ${w.sku_count} SKU & ${w.user_count} user. Klik OK untuk NONAKTIFKAN (jaga histori).`
            : `Hapus gudang "${w.name}"? Tindakan ini tidak bisa di-undo.`;
        if (! confirm(msg)) return;
        router.delete(route('master.warehouses.destroy', w.id), {
            preserveScroll: true,
            onSuccess: () => toast.success('Gudang diproses'),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal'),
        });
    }

    function doSearch(e: FormEvent) {
        e.preventDefault();
        router.get(route('master.warehouses.index'), { search },
            { preserveState: true, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Gudang / Cabang</h2>}>
            <Head title="Master Gudang" />

            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <form onSubmit={doSearch} className="flex gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama atau kode"
                            className="w-72"
                        />
                        <Button type="submit" variant="outline">Cari</Button>
                    </form>
                    <Button type="button" onClick={startCreate}>+ Tambah Gudang</Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Tipe</TableHead>
                                    <TableHead className="text-right">SKU Aktif</TableHead>
                                    <TableHead className="text-right">Staff</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {warehouses.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground">
                                            Belum ada gudang.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {warehouses.data.map((w) => (
                                    <TableRow key={w.id}>
                                        <TableCell className="font-mono text-sm">{w.code}</TableCell>
                                        <TableCell className="font-medium">
                                            {w.name}
                                            {w.is_default && (
                                                <Badge variant="info" className="ml-2">default</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {warehouseTypes[w.warehouse_type] ?? w.warehouse_type}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {w.sku_count > 0 ? formatQty(w.sku_count, 0) : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {w.user_count > 0 ? w.user_count : '—'}
                                        </TableCell>
                                        <TableCell>
                                            {w.is_active
                                                ? <Badge variant="success">aktif</Badge>
                                                : <Badge variant="muted">nonaktif</Badge>}
                                        </TableCell>
                                        <TableCell className="text-right space-x-1">
                                            <Button size="sm" variant="ghost" onClick={() => startEdit(w)}>
                                                Edit
                                            </Button>
                                            <Button size="sm" variant="ghost" onClick={() => destroy(w)}>
                                                {(w.sku_count > 0 || w.user_count > 0) ? 'Nonaktifkan' : 'Hapus'}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {warehouses.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>{warehouses.from}–{warehouses.to} dari {warehouses.total}</div>
                        <div className="flex gap-1">
                            {warehouses.links.map((l, i) => (
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
                        <DialogTitle>{isEdit ? 'Edit Gudang' : 'Tambah Gudang'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="w-code">Kode *</Label>
                                <Input id="w-code" value={form.code}
                                    onChange={(e) => setForm({ ...form, code: e.target.value })}
                                    required maxLength={32} placeholder="WH-MAIN" autoFocus
                                    className="font-mono uppercase" />
                            </div>
                            <div>
                                <Label htmlFor="w-name">Nama *</Label>
                                <Input id="w-name" value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    required maxLength={120} placeholder="Cabang Utama" />
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="w-type">Tipe Gudang *</Label>
                            <select
                                id="w-type"
                                value={form.warehouse_type}
                                onChange={(e) => setForm({ ...form, warehouse_type: e.target.value })}
                                className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                required
                            >
                                <option value="petshop">Petshop</option>
                                <option value="klinik">Klinik</option>
                                <option value="apotek_klinik">Apotek Klinik</option>
                                <option value="gudang">Gudang</option>
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="w-addr">Alamat (opsional)</Label>
                            <Input id="w-addr" value={form.address}
                                onChange={(e) => setForm({ ...form, address: e.target.value })}
                                maxLength={500} />
                        </div>
                        <div className="flex flex-wrap gap-4 pt-2">
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox"
                                    checked={form.is_active}
                                    onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                                Aktif
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input type="checkbox"
                                    checked={form.is_default}
                                    onChange={(e) => setForm({ ...form, is_default: e.target.checked })} />
                                Jadikan default
                            </label>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Default warehouse dipakai POS sebagai fallback. Hanya boleh 1 default
                            aktif — kalau di-toggle, gudang default lama otomatis di-unset.
                        </p>

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)} disabled={submitting}>
                                Batal
                            </Button>
                            <Button type="submit" disabled={submitting}>
                                {submitting ? 'Menyimpan…' : isEdit ? 'Simpan Perubahan' : 'Tambah Gudang'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
