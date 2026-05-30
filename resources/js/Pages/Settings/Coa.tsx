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

interface Account {
    id: number;
    code: string;
    name: string;
    type: string;
    parent_id: number | null;
    level: number;
    normal_balance: string;
    is_active: boolean;
    cash_type: 'cash' | 'bank' | null;
    bank_name: string | null;
    account_no: string | null;
    is_system: boolean;
    is_used: boolean;
    is_locked: boolean;
}

interface Props {
    accounts: Account[];
    types: string[];
}

interface FormState {
    id?: number;
    code: string;
    name: string;
    type: string;
    parent_id: string;
    is_active: boolean;
    cash_type: '' | 'cash' | 'bank';
    bank_name: string;
    account_no: string;
    locked: boolean;
}

const TYPE_LABEL: Record<string, string> = {
    asset: 'Aset',
    liability: 'Kewajiban',
    equity: 'Modal',
    revenue: 'Pendapatan',
    expense: 'Beban',
    cogs: 'HPP',
};

const EMPTY: FormState = {
    code: '',
    name: '',
    type: 'asset',
    parent_id: '',
    is_active: true,
    cash_type: '',
    bank_name: '',
    account_no: '',
    locked: false,
};

export default function Coa({ accounts, types }: Props) {
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(EMPTY);
    const isEdit = form.id !== undefined;
    const parents = accounts.filter((a) => a.level === 1);

    function startCreate() {
        setForm(EMPTY);
        setOpen(true);
    }

    function startEdit(a: Account) {
        setForm({
            id: a.id,
            code: a.code,
            name: a.name,
            type: a.type,
            parent_id: a.parent_id ? String(a.parent_id) : '',
            is_active: a.is_active,
            cash_type: a.cash_type ?? '',
            bank_name: a.bank_name ?? '',
            account_no: a.account_no ?? '',
            locked: a.is_locked,
        });
        setOpen(true);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const payload = {
            code: form.code,
            name: form.name,
            type: form.type,
            parent_id: form.parent_id ? Number(form.parent_id) : null,
            is_active: form.is_active,
            cash_type: form.cash_type || null,
            bank_name: form.bank_name || null,
            account_no: form.account_no || null,
        };
        const opts = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(isEdit ? 'Akun diperbarui' : 'Akun ditambahkan');
                setOpen(false);
            },
            onError: (errs: Record<string, string>) =>
                toast.error(Object.values(errs)[0] ?? 'Gagal menyimpan'),
        };
        if (isEdit) {
            router.put(route('settings.coa.update', form.id), payload, opts);
        } else {
            router.post(route('settings.coa.store'), payload, opts);
        }
    }

    function remove(a: Account) {
        if (!confirm(`Hapus akun ${a.code} — ${a.name}?`)) return;
        router.delete(route('settings.coa.destroy', a.id), {
            preserveScroll: true,
            onSuccess: () => toast.success(`Akun ${a.code} dihapus`),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal menghapus'),
        });
    }

    const isAsset = form.type === 'asset';

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Chart of Accounts</h2>}
        >
            <Head title="Chart of Accounts" />

            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <h3 className="text-lg font-medium">Daftar Akun ({accounts.length})</h3>
                    <Button onClick={startCreate} className="min-h-11">
                        + Tambah Akun
                    </Button>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>Nama</TableHead>
                                    <TableHead>Tipe</TableHead>
                                    <TableHead>Saldo Normal</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Aksi</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {accounts.map((a) => (
                                    <TableRow key={a.id} className={a.level === 1 ? 'bg-gray-50' : ''}>
                                        <TableCell className="font-mono">{a.code}</TableCell>
                                        <TableCell
                                            className={a.level === 1 ? 'font-semibold' : 'pl-6'}
                                        >
                                            {a.name}
                                            {a.cash_type && (
                                                <Badge variant="secondary" className="ml-2">
                                                    {a.cash_type === 'bank' ? 'Bank' : 'Kas'}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{TYPE_LABEL[a.type] ?? a.type}</TableCell>
                                        <TableCell className="capitalize">
                                            {a.normal_balance}
                                        </TableCell>
                                        <TableCell>
                                            {a.is_system ? (
                                                <Badge variant="info">Sistem</Badge>
                                            ) : a.is_used ? (
                                                <Badge variant="muted">Terpakai</Badge>
                                            ) : (
                                                <Badge
                                                    variant={
                                                        a.is_active ? 'success' : 'destructive'
                                                    }
                                                >
                                                    {a.is_active ? 'Aktif' : 'Nonaktif'}
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => startEdit(a)}
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-destructive disabled:opacity-40"
                                                disabled={a.is_locked}
                                                onClick={() => remove(a)}
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
                        <DialogTitle>{isEdit ? 'Edit Akun' : 'Tambah Akun'}</DialogTitle>
                    </DialogHeader>
                    {form.locked && (
                        <p className="rounded bg-amber-50 p-2 text-xs text-amber-700">
                            Akun terkunci (sistem / sudah dipakai jurnal). Kode & tipe tidak
                            bisa diubah; akun tidak bisa dihapus. Hanya nama, status, dan
                            metadata bank yang bisa disunting.
                        </p>
                    )}
                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <Label htmlFor="c-code">Kode</Label>
                                <Input
                                    id="c-code"
                                    value={form.code}
                                    onChange={(e) => setForm({ ...form, code: e.target.value })}
                                    disabled={form.locked}
                                    required
                                />
                            </div>
                            <div>
                                <Label htmlFor="c-type">Tipe</Label>
                                <select
                                    id="c-type"
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base disabled:opacity-60"
                                    value={form.type}
                                    onChange={(e) => setForm({ ...form, type: e.target.value })}
                                    disabled={form.locked}
                                >
                                    {types.map((t) => (
                                        <option key={t} value={t}>
                                            {TYPE_LABEL[t] ?? t}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="c-name">Nama</Label>
                            <Input
                                id="c-name"
                                value={form.name}
                                onChange={(e) => setForm({ ...form, name: e.target.value })}
                                required
                            />
                        </div>
                        {!form.locked && (
                            <div>
                                <Label htmlFor="c-parent">Parent (opsional)</Label>
                                <select
                                    id="c-parent"
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                    value={form.parent_id}
                                    onChange={(e) =>
                                        setForm({ ...form, parent_id: e.target.value })
                                    }
                                >
                                    <option value="">— Akun induk —</option>
                                    {parents
                                        .filter((p) => p.type === form.type)
                                        .map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.code} — {p.name}
                                            </option>
                                        ))}
                                </select>
                            </div>
                        )}
                        {isAsset && (
                            <div className="grid grid-cols-3 gap-3">
                                <div>
                                    <Label htmlFor="c-cash">Jenis Kas</Label>
                                    <select
                                        id="c-cash"
                                        className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                        value={form.cash_type}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                cash_type: e.target.value as FormState['cash_type'],
                                            })
                                        }
                                    >
                                        <option value="">— Bukan —</option>
                                        <option value="cash">Kas</option>
                                        <option value="bank">Bank</option>
                                    </select>
                                </div>
                                <div>
                                    <Label htmlFor="c-bank">Nama Bank</Label>
                                    <Input
                                        id="c-bank"
                                        value={form.bank_name}
                                        onChange={(e) =>
                                            setForm({ ...form, bank_name: e.target.value })
                                        }
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="c-acc">No. Rekening</Label>
                                    <Input
                                        id="c-acc"
                                        value={form.account_no}
                                        onChange={(e) =>
                                            setForm({ ...form, account_no: e.target.value })
                                        }
                                    />
                                </div>
                            </div>
                        )}
                        <div className="flex items-center gap-2">
                            <input
                                id="c-active"
                                type="checkbox"
                                checked={form.is_active}
                                onChange={(e) =>
                                    setForm({ ...form, is_active: e.target.checked })
                                }
                            />
                            <Label htmlFor="c-active">Aktif</Label>
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
