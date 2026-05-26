import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { rupiah } from '@/lib/utils';
import PeriodFilter from '../_components/PeriodFilter';
import { ColumnOption } from '../_components/ExportColumnPickerModal';

interface Row {
    id: number;
    code: string;
    name: string;
    type: string;
    normal_balance: 'debit' | 'credit';
    total_debit: number;
    total_credit: number;
    saldo: number;
}

interface Props {
    filters: { from: string; to: string; warehouse_id: number | null };
    rows: Row[];
    totals: { debit: number; credit: number; balanced: boolean };
    available_columns: ColumnOption[];
}

export default function TrialBalance({ filters, rows, totals, available_columns }: Props) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Trial Balance (Neraca Saldo)</h2>}
        >
            <Head title="Trial Balance" />
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <PeriodFilter
                    routeName="reports.trial_balance"
                    from={filters.from}
                    to={filters.to}
                    showWarehouse={false}
                    availableColumns={available_columns}
                />

                {!totals.balanced && (
                    <div className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                        <strong>Peringatan:</strong> Total Debit ≠ Total Kredit. Selisih{' '}
                        {rupiah(totals.debit - totals.credit)}. Ada jurnal tidak balance — perlu
                        investigasi.
                    </div>
                )}

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>Nama Akun</TableHead>
                                    <TableHead>Tipe</TableHead>
                                    <TableHead className="text-right">Total Debit</TableHead>
                                    <TableHead className="text-right">Total Kredit</TableHead>
                                    <TableHead className="text-right">Saldo</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="text-center text-sm italic text-gray-500">
                                            Tidak ada movement dalam periode ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell className="font-mono text-xs">{r.code}</TableCell>
                                        <TableCell>{r.name}</TableCell>
                                        <TableCell className="text-xs text-gray-500">{r.type}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {r.total_debit > 0 ? rupiah(r.total_debit) : '-'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {r.total_credit > 0 ? rupiah(r.total_credit) : '-'}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {rupiah(r.saldo)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                                <TableRow className="border-t-2 border-gray-400">
                                    <TableCell colSpan={3} className="text-right font-bold">
                                        TOTAL
                                    </TableCell>
                                    <TableCell className="text-right font-bold tabular-nums">
                                        {rupiah(totals.debit)}
                                    </TableCell>
                                    <TableCell className="text-right font-bold tabular-nums">
                                        {rupiah(totals.credit)}
                                    </TableCell>
                                    <TableCell />
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="rounded border border-gray-200 bg-gray-50 p-3 text-sm">
                    Status:{' '}
                    {totals.balanced ? (
                        <span className="font-semibold text-green-700">
                            ✓ BALANCE (Debit = Kredit)
                        </span>
                    ) : (
                        <span className="font-semibold text-red-700">✗ TIDAK BALANCE</span>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
