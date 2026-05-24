import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
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

interface Customer {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    email: string | null;
    birthday: string | null;
    address: string | null;
    notes: string | null;
    is_active: boolean;
    total_spent: string;
    points: number;
    vetly_customer_id: string | null;
    created_at: string;
}

interface SaleRow {
    id: number;
    invoice_no: string;
    date: string;
    payment_method: 'cash' | 'transfer' | 'qris' | null;
    total: string;
    status: string;
    warehouse: { id: number; code: string; name: string } | null;
    cashier: { id: number; name: string } | null;
}

interface Paginated {
    data: SaleRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

interface Props {
    customer: Customer;
    sales: Paginated;
    stats: {
        total_sales: number;
        total_spent: number;
    };
}

const STATUS_VARIANT: Record<string, 'success' | 'destructive' | 'muted' | 'info'> = {
    completed: 'success',
    void: 'destructive',
    refunded: 'destructive',
    draft: 'muted',
};

export default function CustomerShow({ customer, sales, stats }: Props) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold">Pelanggan: {customer.name}</h2>
                    <Link
                        href={route('master.customers.index')}
                        className="text-sm text-sky-700 hover:underline"
                    >
                        ← kembali ke daftar
                    </Link>
                </div>
            }
        >
            <Head title={`Pelanggan — ${customer.name}`} />

            <div className="mx-auto max-w-6xl space-y-4 p-4">
                {/* Info card */}
                <Card>
                    <CardContent className="space-y-3 p-4">
                        <div className="flex flex-wrap items-baseline gap-2">
                            <h3 className="text-lg font-semibold">{customer.name}</h3>
                            <span className="font-mono text-sm text-muted-foreground">{customer.code}</span>
                            {! customer.is_active && (
                                <Badge variant="muted">nonaktif</Badge>
                            )}
                            {customer.vetly_customer_id && (
                                <Badge variant="info">linked Klinik</Badge>
                            )}
                        </div>
                        <div className="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                            <Info label="No HP" value={customer.phone ?? '—'} />
                            <Info label="Email" value={customer.email ?? '—'} />
                            <Info label="Tanggal Lahir" value={customer.birthday ?? '—'} />
                            <Info label="Member sejak" value={customer.created_at.split('T')[0]} />
                            {customer.address && (
                                <div className="col-span-4">
                                    <Info label="Alamat" value={customer.address} />
                                </div>
                            )}
                            {customer.notes && (
                                <div className="col-span-4">
                                    <Info label="Catatan" value={customer.notes} />
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Stats summary */}
                <div className="grid grid-cols-2 gap-3">
                    <StatBox label="Total Transaksi" value={`${stats.total_sales}`} />
                    <StatBox label="Total Belanja" value={rupiah(stats.total_spent)} highlight />
                </div>

                {/* Sales history */}
                <Card>
                    <CardContent className="p-0">
                        <div className="border-b p-3 font-semibold">Riwayat Belanja</div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Tanggal</TableHead>
                                    <TableHead>Invoice</TableHead>
                                    <TableHead>Cabang</TableHead>
                                    <TableHead>Kasir</TableHead>
                                    <TableHead>Metode</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sales.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="text-center text-muted-foreground">
                                            Belum ada transaksi.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {sales.data.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell className="whitespace-nowrap text-sm">
                                            {formatDateID(s.date)}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">{s.invoice_no}</TableCell>
                                        <TableCell className="text-sm">{s.warehouse?.name ?? '—'}</TableCell>
                                        <TableCell className="text-sm">{s.cashier?.name ?? '—'}</TableCell>
                                        <TableCell>
                                            {s.payment_method
                                                ? <Badge variant="muted" className="uppercase text-[10px]">
                                                      {s.payment_method}
                                                  </Badge>
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right font-semibold">
                                            {rupiah(s.total)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={STATUS_VARIANT[s.status] ?? 'muted'}>
                                                {s.status}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {sales.total > 0 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <div>{sales.from}–{sales.to} dari {sales.total}</div>
                        <div className="flex gap-1">
                            {sales.links.map((l, i) => (
                                <Button
                                    key={i}
                                    variant={l.active ? 'default' : 'outline'}
                                    size="sm"
                                    disabled={! l.url}
                                    onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true })}
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="font-medium">{value}</div>
        </div>
    );
}

function StatBox({ label, value, highlight }: { label: string; value: string; highlight?: boolean }) {
    return (
        <div className={`rounded-md border p-4 text-center ${highlight ? 'bg-primary/5' : ''}`}>
            <div className={`text-2xl font-bold ${highlight ? 'text-primary' : ''}`}>{value}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}
