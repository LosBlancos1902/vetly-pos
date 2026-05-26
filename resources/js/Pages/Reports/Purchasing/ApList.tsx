import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
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
import { type FormEvent } from 'react';
import ExportButton from '../_components/ExportButton';
import { ColumnOption } from '../_components/ExportColumnPickerModal';

interface Row {
    id: number;
    ap_no: string;
    received_at: string | null;
    supplier_code: string;
    supplier_name: string;
    po_no: string | null;
    gr_no: string | null;
    amount: number;
    paid_amount: number;
    remaining: number;
    due_date: string | null;
    status: 'open' | 'partially_paid' | 'paid' | 'void';
}

interface Props {
    filters: { from: string; to: string; status: string | null };
    rows: Row[];
    available_columns: ColumnOption[];
    status_options: string[];
}

export default function ApList({ filters, rows, available_columns, status_options }: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.ap_list'), params, { preserveScroll: true });
    }

    const totalAmount = rows.reduce((a, r) => a + r.amount, 0);
    const totalPaid = rows.reduce((a, r) => a + r.paid_amount, 0);
    const totalRemaining = rows.reduce((a, r) => a + r.remaining, 0);

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Daftar AP (Semua Hutang Supplier)</h2>}
        >
            <Head title="Daftar AP" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
                >
                    <div>
                        <Label htmlFor="from">Tgl Terima (dari)</Label>
                        <Input id="from" name="from" type="date" defaultValue={filters.from} />
                    </div>
                    <div>
                        <Label htmlFor="to">Tgl Terima (sampai)</Label>
                        <Input id="to" name="to" type="date" defaultValue={filters.to} />
                    </div>
                    <div>
                        <Label htmlFor="status">Status</Label>
                        <select
                            id="status"
                            name="status"
                            defaultValue={filters.status ?? ''}
                            className="block h-9 rounded border border-gray-300 bg-white px-2 text-sm"
                        >
                            <option value="">— Semua —</option>
                            {status_options.map((s) => (
                                <option key={s} value={s}>
                                    {s}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="ml-auto flex gap-2">
                        <Button type="submit">Tampilkan</Button>
                        <ExportButton
                            baseUrl={route('reports.ap_list')}
                            params={{
                                from: filters.from,
                                to: filters.to,
                                status: filters.status,
                            }}
                            columns={available_columns}
                        />
                    </div>
                </form>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-gray-500">Total Nilai</div>
                            <div className="mt-1 text-lg font-semibold tabular-nums">
                                {rupiah(totalAmount)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="text-xs text-gray-500">Total Dibayar</div>
                            <div className="mt-1 text-lg font-semibold tabular-nums text-green-700">
                                {rupiah(totalPaid)}
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="border-indigo-300 bg-indigo-50">
                        <CardContent className="p-4">
                            <div className="text-xs text-indigo-700">Total Sisa</div>
                            <div className="mt-1 text-lg font-bold tabular-nums text-indigo-900">
                                {rupiah(totalRemaining)}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>No AP</TableHead>
                                    <TableHead>Tgl Terima</TableHead>
                                    <TableHead>Supplier</TableHead>
                                    <TableHead>No PO</TableHead>
                                    <TableHead>No GR</TableHead>
                                    <TableHead className="text-right">Nilai</TableHead>
                                    <TableHead className="text-right">Dibayar</TableHead>
                                    <TableHead className="text-right">Sisa</TableHead>
                                    <TableHead>Jatuh Tempo</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={10} className="text-center text-sm italic text-gray-500">
                                            Tidak ada AP dalam periode.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell className="font-mono text-xs">{r.ap_no}</TableCell>
                                        <TableCell>{r.received_at ? formatDateID(r.received_at) : '-'}</TableCell>
                                        <TableCell>
                                            <div className="text-sm">{r.supplier_name}</div>
                                            <div className="text-xs text-gray-500">{r.supplier_code}</div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{r.po_no ?? '-'}</TableCell>
                                        <TableCell className="font-mono text-xs">{r.gr_no ?? '-'}</TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(r.amount)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(r.paid_amount)}</TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">{rupiah(r.remaining)}</TableCell>
                                        <TableCell>{r.due_date ? formatDateID(r.due_date) : '-'}</TableCell>
                                        <TableCell>
                                            <Badge
                                                variant={
                                                    r.status === 'paid'
                                                        ? 'success'
                                                        : r.status === 'void'
                                                          ? 'muted'
                                                          : r.status === 'partially_paid'
                                                            ? 'warning'
                                                            : 'secondary'
                                                }
                                            >
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
