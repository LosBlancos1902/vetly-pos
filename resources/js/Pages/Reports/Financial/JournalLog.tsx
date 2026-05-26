import { Head, Link } from '@inertiajs/react';
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
import { formatDateID, rupiah } from '@/lib/utils';
import PeriodFilter from '../_components/PeriodFilter';

interface CoaLite {
    id: number;
    code: string;
    name: string;
}
interface Entry {
    id: number;
    coa: CoaLite | null;
    debit: string;
    credit: string;
    description: string | null;
}
interface Journal {
    id: number;
    journal_no: string;
    date: string;
    description: string;
    ref_type: string | null;
    ref_id: number | null;
    entries: Entry[];
}
interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Props {
    filters: { from: string; to: string; warehouse_id: number | null };
    journals: Paginated<Journal>;
}

export default function JournalLog({ filters, journals }: Props) {
    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Jurnal Umum</h2>}
        >
            <Head title="Jurnal Umum" />
            <div className="mx-auto max-w-7xl space-y-4 p-4">
                <PeriodFilter
                    routeName="reports.journal_log"
                    from={filters.from}
                    to={filters.to}
                    showWarehouse={false}
                />

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Tanggal</TableHead>
                                    <TableHead>No Jurnal</TableHead>
                                    <TableHead>Akun</TableHead>
                                    <TableHead className="text-right">Debit</TableHead>
                                    <TableHead className="text-right">Kredit</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {journals.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="text-center text-sm italic text-gray-500">
                                            Tidak ada jurnal dalam periode ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {journals.data.map((j) => (
                                    <JournalBlock key={j.id} j={j} />
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap gap-2">
                    {journals.links.map((l, i) => (
                        <Link
                            key={i}
                            href={l.url ?? '#'}
                            preserveScroll
                            className={
                                'rounded border px-3 py-1 text-sm ' +
                                (l.active
                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                    : l.url
                                      ? 'border-gray-300 bg-white hover:bg-gray-50'
                                      : 'cursor-not-allowed border-gray-200 bg-gray-100 text-gray-400')
                            }
                            dangerouslySetInnerHTML={{ __html: l.label }}
                        />
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function JournalBlock({ j }: { j: Journal }) {
    return (
        <>
            <TableRow className="bg-gray-50">
                <TableCell>{formatDateID(j.date)}</TableCell>
                <TableCell className="font-mono text-xs">{j.journal_no}</TableCell>
                <TableCell colSpan={3}>
                    <div className="text-sm">{j.description}</div>
                    {j.ref_type && (
                        <div className="text-xs text-gray-500">
                            Ref: {j.ref_type} #{j.ref_id}
                        </div>
                    )}
                </TableCell>
            </TableRow>
            {j.entries.map((e) => (
                <TableRow key={e.id}>
                    <TableCell />
                    <TableCell />
                    <TableCell>
                        <span className="font-mono text-xs">{e.coa?.code}</span>{' '}
                        {e.coa?.name}
                        {e.description && (
                            <div className="text-xs text-gray-500">{e.description}</div>
                        )}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                        {parseFloat(e.debit) > 0 ? rupiah(e.debit) : ''}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                        {parseFloat(e.credit) > 0 ? rupiah(e.credit) : ''}
                    </TableCell>
                </TableRow>
            ))}
        </>
    );
}
