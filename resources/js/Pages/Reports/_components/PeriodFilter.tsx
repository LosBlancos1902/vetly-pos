import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { type FormEvent } from 'react';
import ExportButton from './ExportButton';
import { ColumnOption } from './ExportColumnPickerModal';

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
    /** Kolom yang bisa dipilih saat export. Kalau ada → tombol Export buka modal. */
    availableColumns?: ColumnOption[];
    children?: React.ReactNode;
}

/**
 * Filter periode + cabang reusable untuk semua halaman laporan.
 * Submit via GET (Inertia visit) supaya URL share-able / bisa di-bookmark.
 * Tombol Export Excel: kalau availableColumns ada, buka modal pilih kolom dulu.
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
    availableColumns,
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

    const exportParams: Record<string, string | number | null | undefined> = {};
    if (from && !onlyTo) exportParams.from = from;
    if (to) exportParams.to = to;
    if (warehouseId) exportParams.warehouse_id = warehouseId;
    Object.entries(extra).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') exportParams[k] = String(v);
    });

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
                {availableColumns && availableColumns.length > 0 && (
                    <ExportButton
                        baseUrl={route(routeName, routeParams as never)}
                        params={exportParams}
                        columns={availableColumns}
                    />
                )}
            </div>
        </form>
    );
}
