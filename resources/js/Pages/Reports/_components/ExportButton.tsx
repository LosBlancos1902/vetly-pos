import { useState } from 'react';
import ExportColumnPickerModal, { ColumnOption } from './ExportColumnPickerModal';

interface Props {
    /** URL endpoint export (tanpa query string). */
    baseUrl: string;
    /** Static params (periode, filter, dim, dst). Akan di-merge ke query string. */
    params: Record<string, string | number | null | undefined>;
    /** Daftar kolom dari BE (Inertia prop available_columns). */
    columns: ColumnOption[];
    /** Optional label tombol. */
    label?: string;
    className?: string;
}

/**
 * Tombol "Export Excel" reusable yang buka modal pilih-kolom dulu.
 * Setelah user pilih → navigate ke URL export dengan columns[]= per key.
 *
 * Browser handle download otomatis karena response Content-Type xlsx +
 * Content-Disposition attachment.
 */
export default function ExportButton({
    baseUrl,
    params,
    columns,
    label = 'Export Excel',
    className,
}: Props) {
    const [open, setOpen] = useState(false);

    function handleExport(keys: string[]) {
        const sp = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') sp.set(k, String(v));
        });
        keys.forEach((k) => sp.append('columns[]', k));
        sp.set('export', '1');
        window.location.href = `${baseUrl}?${sp.toString()}`;
    }

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={
                    className ??
                    'inline-flex h-9 items-center rounded border border-gray-300 bg-white px-4 text-sm font-medium hover:bg-gray-50'
                }
            >
                {label}
            </button>
            <ExportColumnPickerModal
                open={open}
                onClose={() => setOpen(false)}
                columns={columns}
                onExport={handleExport}
            />
        </>
    );
}
