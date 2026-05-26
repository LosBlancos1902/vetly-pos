import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import { useEffect, useMemo, useState } from 'react';

export interface ColumnOption {
    key: string;
    label: string;
    default: boolean;
}

interface Props {
    open: boolean;
    onClose: () => void;
    title?: string;
    columns: ColumnOption[];
    /** Called dengan list of selected keys (urutan sesuai daftar kolom). */
    onExport: (selectedKeys: string[]) => void;
}

/**
 * Modal pilih kolom untuk Export Excel.
 *
 * - Default state: semua kolom dengan `default=true` tercentang.
 * - User bisa toggle individual + Select All / Reset Default.
 * - Klik Export → callback dengan selected keys → caller navigate ke
 *   URL export dengan ?columns[]= per key.
 *
 * Urutan kolom di output = urutan di `columns` array (definisi BE), bukan
 * urutan klik user. Konsisten & predictable.
 */
export default function ExportColumnPickerModal({
    open,
    onClose,
    title = 'Pilih Kolom Export',
    columns,
    onExport,
}: Props) {
    const defaultKeys = useMemo(
        () => new Set(columns.filter((c) => c.default).map((c) => c.key)),
        [columns],
    );
    const [selected, setSelected] = useState<Set<string>>(defaultKeys);

    // Reset state tiap kali modal dibuka (jangan persist state lintas-open).
    useEffect(() => {
        if (open) setSelected(new Set(defaultKeys));
    }, [open, defaultKeys]);

    function toggle(key: string) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    }

    function selectAll() {
        setSelected(new Set(columns.map((c) => c.key)));
    }

    function selectNone() {
        setSelected(new Set());
    }

    function resetDefault() {
        setSelected(new Set(defaultKeys));
    }

    function handleExport() {
        // Pertahankan urutan kolom sesuai definisi (bukan urutan toggle user).
        const ordered = columns.map((c) => c.key).filter((k) => selected.has(k));
        if (ordered.length === 0) {
            return; // tombol di-disable kalau 0
        }
        onExport(ordered);
        onClose();
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                if (!o) onClose();
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        Centang kolom yang ingin masuk ke file Excel. Format tetap flat-tabular
                        (1 baris = 1 record), aman untuk pivot/filter.
                    </DialogDescription>
                </DialogHeader>

                <div className="-mx-2 flex flex-wrap gap-2 px-2 py-1 text-xs">
                    <button
                        type="button"
                        onClick={selectAll}
                        className="rounded border border-gray-300 px-2 py-1 hover:bg-gray-50"
                    >
                        Pilih Semua
                    </button>
                    <button
                        type="button"
                        onClick={selectNone}
                        className="rounded border border-gray-300 px-2 py-1 hover:bg-gray-50"
                    >
                        Kosongkan
                    </button>
                    <button
                        type="button"
                        onClick={resetDefault}
                        className="rounded border border-gray-300 px-2 py-1 hover:bg-gray-50"
                    >
                        Reset Default
                    </button>
                    <span className="ml-auto self-center text-gray-500">
                        {selected.size} / {columns.length} kolom
                    </span>
                </div>

                <div className="max-h-[55vh] space-y-1 overflow-y-auto rounded border border-gray-200 p-2">
                    {columns.map((c) => (
                        <label
                            key={c.key}
                            className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-gray-50"
                        >
                            <input
                                type="checkbox"
                                checked={selected.has(c.key)}
                                onChange={() => toggle(c.key)}
                            />
                            <span>{c.label}</span>
                            {c.default && (
                                <span className="ml-auto text-[10px] text-gray-400">default</span>
                            )}
                        </label>
                    ))}
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        onClick={handleExport}
                        disabled={selected.size === 0}
                    >
                        Export Excel ({selected.size} kolom)
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
