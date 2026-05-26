import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
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
import { formatDateID, rupiah } from '@/lib/utils';
import PeriodFilter from '../_components/PeriodFilter';
import { ColumnOption } from '../_components/ExportColumnPickerModal';

interface Row {
    id: number;
    opened_at: string;
    closed_at: string | null;
    cashier_name: string;
    warehouse_code: string | null;
    warehouse_name: string | null;
    opening_cash: number;
    expected_cash: number | null;
    closing_cash: number | null;
    cash_variance: number | null;
    status: 'open' | 'closed';
}

interface Props {
    filters: { from: string; to: string };
    rows: Row[];
    available_columns: ColumnOption[];
}

export default function Shifts({ filters, rows, available_columns }: Props) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Laporan Shift Kasir</h2>}
        >
            <Head title="Shift Kasir" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <PeriodFilter
                    routeName="reports.shifts"
                    from={filters.from}
                    to={filters.to}
                    showWarehouse={false}
                    availableColumns={available_columns}
                />

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kasir</TableHead>
                                    <TableHead>Cabang</TableHead>
                                    <TableHead>Buka</TableHead>
                                    <TableHead>Tutup</TableHead>
                                    <TableHead className="text-right">Kas Awal</TableHead>
                                    <TableHead className="text-right">Kas Harapan</TableHead>
                                    <TableHead className="text-right">Kas Tutup</TableHead>
                                    <TableHead className="text-right">Selisih</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={9} className="text-center text-sm italic text-gray-500">
                                            Tidak ada shift dalam periode.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>{r.cashier_name}</TableCell>
                                        <TableCell className="text-xs">
                                            {r.warehouse_code && (
                                                <>
                                                    <span className="font-mono">{r.warehouse_code}</span>{' '}
                                                </>
                                            )}
                                            {r.warehouse_name ?? '-'}
                                        </TableCell>
                                        <TableCell className="text-xs">{formatDateID(r.opened_at)}</TableCell>
                                        <TableCell className="text-xs">
                                            {r.closed_at ? formatDateID(r.closed_at) : '-'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(r.opening_cash)}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {r.expected_cash !== null ? rupiah(r.expected_cash) : '-'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {r.closing_cash !== null ? rupiah(r.closing_cash) : '-'}
                                        </TableCell>
                                        <TableCell
                                            className={
                                                'text-right font-medium tabular-nums ' +
                                                (r.cash_variance !== null && r.cash_variance !== 0
                                                    ? r.cash_variance > 0
                                                        ? 'text-green-700'
                                                        : 'text-red-700'
                                                    : '')
                                            }
                                        >
                                            {r.cash_variance !== null ? rupiah(r.cash_variance) : '-'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={r.status === 'open' ? 'warning' : 'secondary'}>
                                                {r.status}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
