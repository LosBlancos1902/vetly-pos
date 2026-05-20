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

interface Role {
    id: number;
    name: string;
}
interface Warehouse {
    id: number;
    code: string;
    name: string;
}
interface UserRow {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    is_active: boolean;
    warehouse_id: number | null;
    last_login_at: string | null;
    roles: Role[];
    warehouse: Warehouse | null;
}

interface Props {
    users: UserRow[];
    roles: Role[];
    warehouses: Warehouse[];
    crossWarehouseRoles: string[];
}

interface FormState {
    id?: number;
    name: string;
    email: string;
    phone: string;
    password: string;
    role: string;
    warehouse_id: string;
    is_active: boolean;
}

const EMPTY_FORM: FormState = {
    name: '',
    email: '',
    phone: '',
    password: '',
    role: '',
    warehouse_id: '',
    is_active: true,
};

export default function Users({ users, roles, warehouses, crossWarehouseRoles }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const isCrossWarehouse = crossWarehouseRoles.includes(form.role);
    const isEdit = form.id !== undefined;

    function startCreate() {
        setForm({ ...EMPTY_FORM, role: roles[0]?.name ?? '' });
        setOpen(true);
    }

    function startEdit(u: UserRow) {
        setForm({
            id: u.id,
            name: u.name,
            email: u.email,
            phone: u.phone ?? '',
            password: '',
            role: u.roles[0]?.name ?? '',
            warehouse_id: u.warehouse_id ? String(u.warehouse_id) : '',
            is_active: u.is_active,
        });
        setOpen(true);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const payload = {
            name: form.name,
            email: form.email,
            phone: form.phone || null,
            password: form.password || undefined,
            role: form.role,
            warehouse_id: form.warehouse_id ? Number(form.warehouse_id) : null,
            is_active: form.is_active,
        };

        const opts = {
            onSuccess: () => {
                toast.success(isEdit ? 'User diperbarui' : 'User ditambahkan');
                setOpen(false);
            },
            onError: (errs: Record<string, string>) => {
                const first = Object.values(errs)[0];
                if (first) toast.error(first);
            },
            preserveScroll: true,
        };

        if (isEdit) {
            router.put(route('settings.users.update', form.id), payload, opts);
        } else {
            router.post(route('settings.users.store'), payload, opts);
        }
    }

    function remove(u: UserRow) {
        if (!confirm(`Hapus user ${u.name}?`)) return;
        router.delete(route('settings.users.destroy', u.id), {
            onSuccess: () => toast.success(`User ${u.name} dihapus`),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal menghapus'),
            preserveScroll: true,
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">User Management</h2>}>
            <Head title="Users" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <h3 className="text-lg font-medium">Daftar Pengguna ({users.length})</h3>
                    <Button onClick={startCreate} className="min-h-11">
                        + Tambah User
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Warehouse</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.map((u) => (
                                    <TableRow key={u.id}>
                                        <TableCell className="font-medium">{u.name}</TableCell>
                                        <TableCell>{u.email}</TableCell>
                                        <TableCell>
                                            {u.roles.map((r) => (
                                                <Badge key={r.id} variant="info" className="mr-1">
                                                    {r.name}
                                                </Badge>
                                            ))}
                                        </TableCell>
                                        <TableCell>
                                            {u.warehouse ? (
                                                <Badge variant="secondary">{u.warehouse.name}</Badge>
                                            ) : (
                                                <Badge variant="muted">Semua warehouse</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={u.is_active ? 'success' : 'destructive'}>
                                                {u.is_active ? 'Aktif' : 'Nonaktif'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => startEdit(u)}
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive"
                                                onClick={() => remove(u)}
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
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{isEdit ? 'Edit User' : 'Tambah User'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-3">
                        <div>
                            <Label htmlFor="u-name">Nama</Label>
                            <Input
                                id="u-name"
                                value={form.name}
                                onChange={(e) => setForm({ ...form, name: e.target.value })}
                                required
                            />
                        </div>
                        <div>
                            <Label htmlFor="u-email">Email</Label>
                            <Input
                                id="u-email"
                                type="email"
                                value={form.email}
                                onChange={(e) => setForm({ ...form, email: e.target.value })}
                                required
                            />
                        </div>
                        <div>
                            <Label htmlFor="u-phone">Phone (opsional)</Label>
                            <Input
                                id="u-phone"
                                value={form.phone}
                                onChange={(e) => setForm({ ...form, phone: e.target.value })}
                            />
                        </div>
                        <div>
                            <Label htmlFor="u-pass">
                                Password {isEdit && <span className="text-xs text-muted-foreground">(kosongkan = tidak ganti)</span>}
                            </Label>
                            <Input
                                id="u-pass"
                                type="password"
                                value={form.password}
                                onChange={(e) => setForm({ ...form, password: e.target.value })}
                                {...(!isEdit ? { required: true } : {})}
                                minLength={6}
                            />
                        </div>
                        <div>
                            <Label htmlFor="u-role">Role</Label>
                            <select
                                id="u-role"
                                value={form.role}
                                onChange={(e) => setForm({ ...form, role: e.target.value })}
                                className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                required
                            >
                                {roles.map((r) => (
                                    <option key={r.id} value={r.name}>
                                        {r.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <Label htmlFor="u-wh">
                                Warehouse{' '}
                                {isCrossWarehouse && (
                                    <span className="text-xs text-muted-foreground">
                                        (opsional — role ini bisa akses semua)
                                    </span>
                                )}
                            </Label>
                            <select
                                id="u-wh"
                                value={form.warehouse_id}
                                onChange={(e) =>
                                    setForm({ ...form, warehouse_id: e.target.value })
                                }
                                className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                required={!isCrossWarehouse}
                            >
                                <option value="">
                                    {isCrossWarehouse ? '— Semua warehouse —' : '— Pilih warehouse —'}
                                </option>
                                {warehouses.map((w) => (
                                    <option key={w.id} value={w.id}>
                                        {w.name} ({w.code})
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex items-center gap-2">
                            <input
                                id="u-active"
                                type="checkbox"
                                checked={form.is_active}
                                onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                            />
                            <Label htmlFor="u-active">Aktif</Label>
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
