import { useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent } from '@/Components/ui/card';

interface WarehouseLite {
    id: number;
    code: string;
    name: string;
}

interface Props {
    warehouses: WarehouseLite[];
    defaultWarehouseId: number | null;
}

export default function StockOpnameCreate({ warehouses, defaultWarehouseId }: Props) {
    const today = new Date().toISOString().slice(0, 10);
    const [form, setForm] = useState({
        warehouse_id: String(defaultWarehouseId ?? ''),
        opname_date: today,
        catatan: '',
    });
    const [submitting, setSubmitting] = useState(false);

    function submit(e: FormEvent) {
        e.preventDefault();
        setSubmitting(true);
        router.post(
            route('inventory.opnames.store'),
            {
                warehouse_id: Number(form.warehouse_id),
                opname_date: form.opname_date,
                catatan: form.catatan || null,
            },
            {
                onSuccess: () => toast.success('Opname draft dibuat'),
                onError: (errs) => toast.error((Object.values(errs)[0] as string) ?? 'Gagal'),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Buat Stock Opname</h2>}>
            <Head title="Buat Stock Opname" />

            <div className="mx-auto max-w-2xl p-4">
                <Card>
                    <CardContent className="p-4">
                        <form onSubmit={submit} className="space-y-4">
                            <div>
                                <Label htmlFor="warehouse">Gudang</Label>
                                <select
                                    id="warehouse"
                                    value={form.warehouse_id}
                                    onChange={(e) => setForm({ ...form, warehouse_id: e.target.value })}
                                    className="flex h-11 w-full rounded-md border border-input bg-background px-3 text-base"
                                    required
                                    disabled={defaultWarehouseId !== null}
                                >
                                    <option value="">— Pilih gudang —</option>
                                    {warehouses.map((w) => (
                                        <option key={w.id} value={w.id}>
                                            {w.name} ({w.code})
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Opname akan menjepret stok sistem saat ini untuk semua produk di gudang yang dipilih.
                                </p>
                            </div>

                            <div>
                                <Label htmlFor="opname-date">Tanggal Opname</Label>
                                <Input
                                    id="opname-date"
                                    type="date"
                                    value={form.opname_date}
                                    onChange={(e) => setForm({ ...form, opname_date: e.target.value })}
                                    required
                                />
                            </div>

                            <div>
                                <Label htmlFor="catatan">Catatan</Label>
                                <Input
                                    id="catatan"
                                    value={form.catatan}
                                    onChange={(e) => setForm({ ...form, catatan: e.target.value })}
                                    placeholder="opname akhir bulan / random check / dst"
                                />
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => router.visit(route('inventory.opnames.index'))}
                                >
                                    Batal
                                </Button>
                                <Button type="submit" disabled={submitting}>
                                    {submitting ? 'Memproses…' : 'Mulai Opname'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
