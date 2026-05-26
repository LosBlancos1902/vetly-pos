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
    type: 'revenue' | 'cogs' | 'expense';
    amount: number;
}

interface Totals {
    revenue: number;
    cogs: number;
    gross_profit: number;
    expense: number;
    net_profit: number;
}

interface Props {
    filters: { from: string; to: string; warehouse_id: number | null };
    warehouses: Array<{ id: number; code: string; name: string }>;
    rows: Account[];
    totals: Totals;
    available_columns: ColumnOption[];
}

export default function ProfitLoss({ filters, warehouses, rows, totals, available_columns }: Props) {
    const rev = rows.filter((r) => r.type === 'revenue');
    const cogs = rows.filter((r) => r.type === 'cogs');
    const exp = rows.filter((r) => r.type === 'expense');

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Laba Rugi (P&L)</h2>}
        >
            <Head title="Laba Rugi" />
            <div className="mx-auto max-w-6xl space-y-4 p-4">
                <PeriodFilter
                    routeName="reports.profit_loss"
                    from={filters.from}
                    to={filters.to}
                    warehouseId={filters.warehouse_id}
                    warehouses={warehouses}
                    warehouseDisabled
                    availableColumns={available_columns}
                />

                <div className="rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <p className="font-medium">Catatan v1 — Laba KOTOR, bukan bersih.</p>
                    <p className="mt-1">
                        Beban operasional (Gaji, Sewa, Listrik, dll. — akun 6xxx) saat ini
                        kosong karena modul input beban manual belum tersedia (menyusul di
                        Batch B). Yang tampil = <strong>Pendapatan − HPP = Laba Kotor</strong>.
                        Filter cabang sementara dinonaktifkan: jurnal v1 dicatat konsolidasi.
                    </p>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kode</TableHead>
                                    <TableHead>Nama Akun</TableHead>
                                    <TableHead className="text-right">Nilai</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <SectionHeader title="PENDAPATAN" />
                                {rev.length === 0 && <EmptyRow />}
                                {rev.map((r) => (
                                    <AccountRow key={r.id} r={r} />
                                ))}
                                <TotalRow label="Total Pendapatan" value={totals.revenue} />

                                <SectionHeader title="HARGA POKOK PENJUALAN (HPP)" />
                                {cogs.length === 0 && <EmptyRow />}
                                {cogs.map((r) => (
                                    <AccountRow key={r.id} r={r} />
                                ))}
                                <TotalRow label="Total HPP" value={totals.cogs} />

                                <TotalRow
                                    label="LABA KOTOR (Pendapatan − HPP)"
                                    value={totals.gross_profit}
                                    bold
                                />

                                <SectionHeader title="BEBAN OPERASIONAL" />
                                {exp.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={3} className="text-center text-xs italic text-gray-500">
                                            Belum ada beban operasional tercatat (modul input beban menyusul Batch B)
                                        </TableCell>
                                    </TableRow>
                                )}
                                {exp.map((r) => (
                                    <AccountRow key={r.id} r={r} />
                                ))}
                                <TotalRow label="Total Beban" value={totals.expense} />

                                <TotalRow
                                    label="LABA BERSIH (Kotor − Beban)"
                                    value={totals.net_profit}
                                    bold
                                />
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function AccountRow({ r }: { r: Account }) {
    return (
        <TableRow>
            <TableCell>{r.code}</TableCell>
            <TableCell>{r.name}</TableCell>
            <TableCell className="text-right tabular-nums">{rupiah(r.amount)}</TableCell>
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
                (tidak ada data dalam periode ini)
            </TableCell>
        </TableRow>
    );
}
