import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { type FormEvent } from 'react';

interface Warehouse {
    id: number;
    code: string;
    name: string;
}

interface Props {
    routeName: string;
    routeParams?: Record<string, unknown>;
    from?: string | null;
    to?: string | null;
    warehouseId?: number | null;
    warehouses?: Warehouse[];
    extra?: Record<string, unknown>;
    showWarehouse?: boolean;
    warehouseDisabled?: boolean;
    onlyTo?: boolean;
    children?: React.ReactNode;
}

/**
 * Filter periode + cabang reusable untuk semua halaman laporan.
 * Submit via GET (Inertia visit) supaya URL share-able / bisa di-bookmark.
 * Tombol Export Excel = same params + ?export=1, langsung download.
 */
export default function PeriodFilter({
    routeName,
    routeParams = {},
    from,
    to,
    warehouseId,
    warehouses = [],
    extra = {},
    showWarehouse = true,
    warehouseDisabled = false,
    onlyTo = false,
    children,
}: Props) {
    function submit(e: FormEvent) {
        e.preventDefault();
        const form = e.target as HTMLFormElement;
        const data = new FormData(form);
        const params: Record<string, string> = {};
        data.forEach((v, k) => {
            if (v !== '' && v !== null) params[k] = String(v);
        });
        Object.entries(extra).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') params[k] = String(v);
        });
        router.get(route(routeName, routeParams as never), params, {
            preserveState: false,
            preserveScroll: true,
        });
    }

    const exportHref = (() => {
        const params = new URLSearchParams();
        if (from && !onlyTo) params.set('from', from);
        if (to) params.set('to', to);
        if (warehouseId) params.set('warehouse_id', String(warehouseId));
        Object.entries(extra).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') params.set(k, String(v));
        });
        params.set('export', '1');
        return `${route(routeName, routeParams as never)}?${params.toString()}`;
    })();

    return (
        <form
            onSubmit={submit}
            className="flex flex-wrap items-end gap-3 rounded border border-gray-200 bg-white p-4"
        >
            {!onlyTo && (
                <div>
                    <Label htmlFor="from">Dari Tanggal</Label>
                    <Input id="from" name="from" type="date" defaultValue={from ?? ''} />
                </div>
            )}
            <div>
                <Label htmlFor="to">{onlyTo ? 'Per Tanggal' : 'Sampai Tanggal'}</Label>
                <Input id="to" name="to" type="date" defaultValue={to ?? ''} />
            </div>
            {showWarehouse && warehouses.length > 0 && (
                <div>
                    <Label htmlFor="warehouse_id">Cabang/Gudang</Label>
                    <select
                        id="warehouse_id"
                        name="warehouse_id"
                        defaultValue={warehouseId ?? ''}
                        disabled={warehouseDisabled}
                        className="block h-9 rounded border border-gray-300 bg-white px-2 text-sm disabled:bg-gray-100"
                    >
                        <option value="">— Semua / Konsolidasi —</option>
                        {warehouses.map((w) => (
                            <option key={w.id} value={w.id}>
                                {w.code} — {w.name}
                            </option>
                        ))}
                    </select>
                </div>
            )}
            {children}
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
    );
}
