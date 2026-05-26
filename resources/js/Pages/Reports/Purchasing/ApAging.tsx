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

interface Row {
    ap_id: number;
    ap_no: string;
    supplier_id: number;
    supplier_code: string;
    supplier_name: string;
    gr_no: string | null;
    received_at: string | null;
    due_date: string | null;
    amount: number;
    paid_amount: number;
    remaining: number;
    days_overdue: number | null;
    bucket: '0-30' | '31-60' | '61-90' | '>90';
    status: 'open' | 'partially_paid';
}

interface Props {
    filters: { as_of: string };
    rows: Row[];
    buckets: Record<'0-30' | '31-60' | '61-90' | '>90', number>;
    total_outstanding: number;
}

export default function ApAging({ filters, rows, buckets, total_outstanding }: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.target as HTMLFormElement);
        const params: Record<string, string> = {};
        fd.forEach((v, k) => {
            if (v) params[k] = String(v);
        });
        router.get(route('reports.ap_aging'), params, { preserveScroll: true });
    }

    const exportHref = `${route('reports.ap_aging')}?as_of=${filters.as_of}&export=1`;

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">AP Aging — Umur Hutang Supplier</h2>}
        >
            <Head title="AP Aging" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
                >
                    <div>
                        <Label htmlFor="as_of">Per Tanggal</Label>
                        <Input id="as_of" name="as_of" type="date" defaultValue={filters.as_of} />
                    </div>
                    <div className="ml-auto flex gap-2">
                        <Button type="submit">Tampilkan</Button>
                        <a
                            href={exportHref}
                            className="inline-flex h-9 items-center rounded border border-gray-300 bg-white px-4 text-sm font-medium hover:bg-gray-50"
                        >
                            Export Excel
                        </a>
                    </div>
                </form>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                    {(['0-30', '31-60', '61-90', '>90'] as const).map((b) => (
                        <Card key={b}>
                            <CardContent className="p-4">
                                <div className="text-xs text-gray-500">Bucket {b}</div>
                                <div className="mt-1 text-lg font-semibold tabular-nums">
                                    {rupiah(buckets[b])}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                    <Card className="border-indigo-300 bg-indigo-50">
                        <CardContent className="p-4">
                            <div className="text-xs text-indigo-700">Total Outstanding</div>
                            <div className="mt-1 text-lg font-bold tabular-nums text-indigo-900">
                                {rupiah(total_outstanding)}
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
                                    <TableHead>Supplier</TableHead>
                                    <TableHead>No GR</TableHead>
                                    <TableHead>Jatuh Tempo</TableHead>
                                    <TableHead className="text-right">Nilai</TableHead>
                                    <TableHead className="text-right">Dibayar</TableHead>
                                    <TableHead className="text-right">Sisa</TableHead>
                                    <TableHead>Overdue</TableHead>
                                    <TableHead>Bucket</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={9} className="text-center text-sm italic text-gray-500">
                                            Tidak ada AP outstanding.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {rows.map((r) => (
                                    <TableRow key={r.ap_id}>
                                        <TableCell className="font-mono text-xs">{r.ap_no}</TableCell>
                                        <TableCell>
                                            <div className="text-sm">{r.supplier_name}</div>
                                            <div className="text-xs text-gray-500">{r.supplier_code}</div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{r.gr_no ?? '-'}</TableCell>
                                        <TableCell>{r.due_date ? formatDateID(r.due_date) : '-'}</TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(r.amount)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{rupiah(r.paid_amount)}</TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">{rupiah(r.remaining)}</TableCell>
                                        <TableCell>
                                            {r.days_overdue !== null && r.days_overdue > 0 ? (
                                                <span className="text-red-600">+{r.days_overdue} hari</span>
                                            ) : r.days_overdue !== null ? (
                                                <span className="text-gray-500">{r.days_overdue}</span>
                                            ) : (
                                                '-'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={r.bucket === '0-30' ? 'secondary' : 'destructive'}>
                                                {r.bucket}
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
