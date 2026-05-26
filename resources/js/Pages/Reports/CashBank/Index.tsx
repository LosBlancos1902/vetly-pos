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
import ExportButton from '../_components/ExportButton';
import { ColumnOption } from '../_components/ExportColumnPickerModal';

interface Coa {
    id: number;
    code: string;
    name: string;
    normal_balance: 'debit' | 'credit';
}

interface Row {
    journal_id: number;
    date: string;
    journal_no: string;
    description: string;
    ref_type: string | null;
    ref_id: number | null;
    entry_description: string | null;
    masuk: number;
    keluar: number;
    saldo: number;
}

interface Totals {
    opening: number;
    in: number;
    out: number;
    closing: number;
}

interface Props {
    filters: { from: string; to: string; coa_id: number | null };
    accounts: Coa[];
    balances: Record<number, number>;
    rows: Row[];
    totals: Totals;
    available_columns: ColumnOption[];
}

export default function CashBankIndex({
    filters,
    accounts,
    balances,
    rows,
    totals,
    available_columns,
}: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.cash_bank'), params, { preserveScroll: true });
    }


    const selected = accounts.find((a) => a.id === filters.coa_id);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Mutasi Kas & Bank</h2>}
        >
            <Head title="Kas & Bank" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                {/* Saldo cards */}
                <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                    {accounts.map((a) => (
                        <Card
                            key={a.id}
                            className={
                                filters.coa_id === a.id
                                    ? 'border-indigo-400 bg-indigo-50'
                                    : ''
                            }
                        >
                            <CardContent className="p-3">
                                <div className="text-xs text-gray-500">
                                    <span className="font-mono">{a.code}</span> {a.name}
                                </div>
                                <div className="mt-1 text-sm font-semibold tabular-nums">
                                    {rupiah(balances[a.id] ?? 0)}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
                >
                    <div className="min-w-[240px]">
                        <Label htmlFor="coa_id">Akun Kas/Bank</Label>
                        <select
                            id="coa_id"
                            name="coa_id"
                            defaultValue={filters.coa_id ?? ''}
                            className="block h-9 w-full rounded border border-gray-300 bg-white px-2 text-sm"
                        >
                            {accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.code} — {a.name}
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
                        {filters.coa_id && (
                            <ExportButton
                                baseUrl={route('reports.cash_bank')}
                                params={{
                                    from: filters.from,
                                    to: filters.to,
                                    coa_id: filters.coa_id,
                                }}
                                columns={available_columns}
                            />
                        )}
                    </div>
                </form>

                {selected && (
                    <Card>
                        <CardContent className="p-0">
                            <div className="grid gap-2 border-b border-gray-100 bg-gray-50 p-4 text-sm sm:grid-cols-4">
                                <div>
                                    <span className="text-xs text-gray-500">Saldo Awal</span>
                                    <div className="font-medium tabular-nums">{rupiah(totals.opening)}</div>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-500">Total Masuk</span>
                                    <div className="font-medium tabular-nums text-green-700">
                                        {rupiah(totals.in)}
                                    </div>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-500">Total Keluar</span>
                                    <div className="font-medium tabular-nums text-red-700">
                                        {rupiah(totals.out)}
                                    </div>
                                </div>
                                <div>
                                    <span className="text-xs text-gray-500">Saldo Akhir</span>
                                    <div className="font-bold tabular-nums">{rupiah(totals.closing)}</div>
                                </div>
                            </div>

                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Tanggal</TableHead>
                                        <TableHead>No Jurnal</TableHead>
                                        <TableHead>Keterangan</TableHead>
                                        <TableHead>Ref</TableHead>
                                        <TableHead className="text-right">Masuk</TableHead>
                                        <TableHead className="text-right">Keluar</TableHead>
                                        <TableHead className="text-right">Saldo</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-right italic">
                                            (Saldo Awal)
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {rupiah(totals.opening)}
                                        </TableCell>
                                    </TableRow>
                                    {rows.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={7} className="text-center text-sm italic text-gray-500">
                                                Tidak ada mutasi dalam periode ini.
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
                                            <TableCell className="text-right tabular-nums text-green-700">
                                                {r.masuk > 0 ? rupiah(r.masuk) : ''}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums text-red-700">
                                                {r.keluar > 0 ? rupiah(r.keluar) : ''}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {rupiah(r.saldo)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}

                <p className="text-xs italic text-gray-500">
                    Mutasi diambil dari jurnal posted di hirarki "Kas" (parent code 1100).
                    Untuk pengeluaran beban operasional manual (gaji, sewa, listrik) — modul
                    input voucher kas/bank menyusul di Batch B.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
