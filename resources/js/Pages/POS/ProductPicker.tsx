import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { rupiah } from '@/lib/utils';

export interface PickerProduct {
    id: number;
    sku: string;
    barcode: string | null;
    name: string;
    type: string;
    price: number;
    base_unit_id: number;
    stock_qty: number | null;
    is_service: boolean;
}

interface Props {
    open: boolean;
    warehouseId: number;
    onClose: () => void;
    onPick: (p: PickerProduct) => void;
}

const TYPE_LABEL: Record<string, { label: string; variant: 'default' | 'info' | 'secondary' }> = {
    saleable_retail: { label: 'RETAIL', variant: 'secondary' },
    compoundable_drug: { label: 'RACIK', variant: 'default' },
    service: { label: 'JASA', variant: 'info' },
    service_with_consumption: { label: 'JASA', variant: 'info' },
};

export default function ProductPicker({ open, warehouseId, onClose, onPick }: Props) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<PickerProduct[]>([]);
    const [loading, setLoading] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (!open) {
            setQ('');
            setResults([]);
            return;
        }
        // autoFocus on open
        setTimeout(() => inputRef.current?.focus(), 50);
    }, [open]);

    useEffect(() => {
        if (!open) return;
        const term = q.trim();
        if (term.length < 2) {
            setResults([]);
            return;
        }
        const t = setTimeout(async () => {
            setLoading(true);
            try {
                const { data } = await axios.get(route('pos.search'), {
                    params: { q: term, warehouse_id: warehouseId },
                });
                setResults(data.results ?? []);
            } catch {
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 250);
        return () => clearTimeout(t);
    }, [q, warehouseId, open]);

    return (
        <Dialog open={open} onOpenChange={(v) => (!v ? onClose() : null)}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Cari Produk</DialogTitle>
                </DialogHeader>
                <Input
                    ref={inputRef}
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder="Ketik nama / SKU / barcode (min. 2 karakter)"
                />
                <div className="max-h-[60vh] overflow-y-auto rounded-md border">
                    {q.trim().length < 2 && (
                        <div className="p-6 text-center text-sm text-muted-foreground">
                            Mulai ketik untuk mencari produk.
                        </div>
                    )}
                    {q.trim().length >= 2 && loading && (
                        <div className="p-6 text-center text-sm text-muted-foreground">Mencari…</div>
                    )}
                    {q.trim().length >= 2 && !loading && results.length === 0 && (
                        <div className="p-6 text-center text-sm text-muted-foreground">
                            Tidak ada produk yang cocok.
                        </div>
                    )}
                    <ul className="divide-y">
                        {results.map((p) => {
                            const meta = TYPE_LABEL[p.type] ?? { label: p.type, variant: 'secondary' as const };
                            return (
                                <li key={p.id}>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            onPick(p);
                                            onClose();
                                        }}
                                        className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left hover:bg-accent focus:bg-accent focus:outline-none"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="truncate font-medium">{p.name}</span>
                                                <Badge variant={meta.variant} className="text-[10px]">
                                                    {meta.label}
                                                </Badge>
                                            </div>
                                            <div className="truncate text-xs text-muted-foreground">
                                                {p.sku}
                                                {p.barcode ? ` · ${p.barcode}` : ''}
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <div className="font-semibold">{rupiah(p.price)}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {p.is_service ? 'jasa' : `stok: ${p.stock_qty ?? 0}`}
                                            </div>
                                        </div>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </DialogContent>
        </Dialog>
    );
}
