import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { formatDateID, rupiah } from '@/lib/utils';
import { type FormEvent } from 'react';

interface Coa {
    id: number;
    code: string;
    name: string;
    type: string;
    normal_balance: 'debit' | 'credit';
}

interface Row {
    journal_id: number;
    date: string;
    journal_no: string;
    description: string;
    ref_type: string | null;
    ref_id: number | null;
    debit: string;
    credit: string;
    entry_description: string | null;
    running_balance: number;
}

interface Props {
    filters: { from: string; to: string; coa_id: number | null };
    accounts: Coa[];
    account: Coa | null;
    opening: number;
    closing: number;
    rows: Row[];
}

export default function GeneralLedger({ filters, accounts, account, opening, closing, rows }: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.general_ledger'), params, { preserveScroll: true });
    }

    const exportHref = (() => {
        const p = new URLSearchParams();
        if (filters.from) p.set('from', filters.from);
        if (filters.to) p.set('to', filters.to);
        if (filters.coa_id) p.set('coa_id', String(filters.coa_id));
        p.set('export', '1');
        return `${route('reports.general_ledger')}?${p.toString()}`;
    })();

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Buku Besar per Akun</h2>}
        >
            <Head title="Buku Besar" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
                >
                    <div className="min-w-[280px] flex-1">
                        <Label htmlFor="coa_id">Akun (COA)</Label>
                        <select
                            id="coa_id"
                            name="coa_id"
                            defaultValue={filters.coa_id ?? ''}
                            className="block h-9 w-full rounded border border-gray-300 bg-white px-2 text-sm"
                        >
                            <option value="">— Pilih akun —</option>
                            {accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.code} — {a.name} ({a.type})
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <Label htmlFor="from">Dari</Label>
                        <Input id="from" name="from" type="date" defaultValue={filters.from} />
                    </div>
                    <div>
                        <Label htmlFor="to">Sampai</Label>
                        <Input id="to" name="to" type="date" defaultValue={filters.to} />
                    </div>
                    <div className="ml-auto flex gap-2">
                        <Button type="submit">Tampilkan</Button>
                        {account && (
                            <a
                                href={exportHref}
                                className="inline-flex h-9 items-center rounded border border-gray-300 bg-white px-4 text-sm font-medium hover:bg-gray-50"
                            >
                                Export Excel
                            </a>
                        )}
                    </div>
                </form>

                {!account && (
                    <Card>
                        <CardContent className="p-6 text-sm text-gray-600">
                            Pilih akun untuk melihat buku besar.
                        </CardContent>
                    </Card>
                )}

                {account && (
                    <Card>
                        <CardContent className="p-0">
                            <div className="border-b border-gray-100 bg-gray-50 px-4 py-3 text-sm">
                                <div>
                                    <strong>
                                        {account.code} — {account.name}
                                    </strong>{' '}
                                    <span className="text-gray-500">
                                        ({account.type}, NB: {account.normal_balance})
                                    </span>
                                </div>
                                <div className="mt-1 flex flex-wrap gap-4 text-xs">
                                    <span>
                                        Saldo Awal:{' '}
                                        <span className="font-medium tabular-nums">
                                            {rupiah(opening)}
                                        </span>
                                    </span>
                                    <span>
                                        Saldo Akhir:{' '}
                                        <span className="font-medium tabular-nums">
                                            {rupiah(closing)}
                                        </span>
                                    </span>
                                </div>
                            </div>

                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tanggal</TableHead>
                                        <TableHead>No Jurnal</TableHead>
                                        <TableHead>Keterangan</TableHead>
                                        <TableHead>Ref</TableHead>
                                        <TableHead className="text-right">Debit</TableHead>
                                        <TableHead className="text-right">Kredit</TableHead>
                                        <TableHead className="text-right">Saldo</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-right italic">
                                            (Saldo Awal)
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {rupiah(opening)}
                                        </TableCell>
                                    </TableRow>
                                    {rows.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center text-xs italic text-gray-500">
                                                (tidak ada mutasi dalam periode)
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {rows.map((r, i) => (
                                        <TableRow key={i}>
                                            <TableCell>{formatDateID(r.date)}</TableCell>
                                            <TableCell className="font-mono text-xs">{r.journal_no}</TableCell>
                                            <TableCell>{r.entry_description ?? r.description}</TableCell>
                                            <TableCell className="text-xs text-gray-500">
                                                {r.ref_type ?? '-'}
                                                {r.ref_id ? ` #${r.ref_id}` : ''}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {parseFloat(r.debit) > 0 ? rupiah(r.debit) : '-'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {parseFloat(r.credit) > 0 ? rupiah(r.credit) : '-'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {rupiah(r.running_balance)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    <TableRow className="border-t-2 border-gray-400">
                                        <TableCell colSpan={6} className="text-right font-bold">
                                            Saldo Akhir
                                        </TableCell>
                                        <TableCell className="text-right font-bold tabular-nums">
                                            {rupiah(closing)}
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
