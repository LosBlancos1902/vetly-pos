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

interface Account {
    id: number;
    code: string;
    name: string;
    type: 'asset' | 'liability' | 'equity';
    saldo: number;
}

interface Totals {
    asset: number;
    liability: number;
    equity: number;
    current_pl: number;
    total_le: number;
    is_balanced: boolean;
}

interface Props {
    filters: { from: string; to: string; warehouse_id: number | null };
    rows: Account[];
    totals: Totals;
    available_columns: ColumnOption[];
}

export default function BalanceSheet({ filters, rows, totals, available_columns }: Props) {
    const asset = rows.filter((r) => r.type === 'asset');
    const liab = rows.filter((r) => r.type === 'liability');
    const equity = rows.filter((r) => r.type === 'equity');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Neraca</h2>}>
            <Head title="Neraca" />
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <PeriodFilter
                    routeName="reports.balance_sheet"
                    to={filters.to}
                    onlyTo
                    availableColumns={available_columns}
                />

                {!totals.is_balanced && (
                    <div className="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                        <strong>Peringatan:</strong> Neraca TIDAK seimbang. Selisih{' '}
                        {rupiah(totals.asset - totals.total_le)}. Cek jurnal anomali.
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead colSpan={3} className="bg-indigo-50 text-base">
                                            ASET
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {asset.length === 0 && <EmptyRow />}
                                    {asset.map((r) => (
                                        <Row key={r.id} r={r} />
                                    ))}
                                    <TotalRow label="TOTAL ASET" value={totals.asset} />
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead colSpan={3} className="bg-indigo-50 text-base">
                                            KEWAJIBAN + EKUITAS
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <SectionHeader title="Kewajiban" />
                                    {liab.length === 0 && <EmptyRow />}
                                    {liab.map((r) => (
                                        <Row key={r.id} r={r} />
                                    ))}
                                    <TotalRow label="Total Kewajiban" value={totals.liability} />

                                    <SectionHeader title="Ekuitas" />
                                    {equity.length === 0 && <EmptyRow />}
                                    {equity.map((r) => (
                                        <Row key={r.id} r={r} />
                                    ))}
                                    <TotalRow label="Total Ekuitas" value={totals.equity} />

                                    <TableRow>
                                        <TableCell colSpan={2} className="font-medium italic">
                                            Laba Berjalan (s/d cutoff)
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {rupiah(totals.current_pl)}
                                        </TableCell>
                                    </TableRow>

                                    <TotalRow
                                        label="TOTAL KEWAJIBAN + EKUITAS + LABA"
                                        value={totals.total_le}
                                        bold
                                    />
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                <div className="rounded border border-gray-200 bg-gray-50 p-3 text-sm">
                    Status:{' '}
                    {totals.is_balanced ? (
                        <span className="font-semibold text-green-700">
                            ✓ SEIMBANG (Aset = Kewajiban + Ekuitas + Laba)
                        </span>
                    ) : (
                        <span className="font-semibold text-red-700">✗ TIDAK SEIMBANG</span>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function Row({ r }: { r: Account }) {
    return (
        <TableRow>
            <TableCell className="w-20">{r.code}</TableCell>
            <TableCell>{r.name}</TableCell>
            <TableCell className="text-right tabular-nums">{rupiah(r.saldo)}</TableCell>
        </TableRow>
    );
}

function SectionHeader({ title }: { title: string }) {
    return (
        <TableRow>
            <TableCell colSpan={3} className="bg-gray-100 font-semibold text-gray-700">
                {title}
            </TableCell>
        </TableRow>
    );
}

function TotalRow({ label, value, bold }: { label: string; value: number; bold?: boolean }) {
    return (
        <TableRow className={bold ? 'border-y-2 border-gray-400' : ''}>
            <TableCell colSpan={2} className={'text-right ' + (bold ? 'font-bold' : 'font-medium')}>
                {label}
            </TableCell>
            <TableCell className={'text-right tabular-nums ' + (bold ? 'font-bold' : 'font-medium')}>
                {rupiah(value)}
            </TableCell>
        </TableRow>
    );
}

function EmptyRow() {
    return (
        <TableRow>
            <TableCell colSpan={3} className="text-center text-xs italic text-gray-500">
                (tidak ada saldo)
            </TableCell>
        </TableRow>
    );
}
