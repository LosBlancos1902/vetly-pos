import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { rupiah } from '@/lib/utils';

export interface SearchProduct {
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

type AddResult = 'added' | 'notfound' | 'error';

interface Props {
    warehouseId: number;
    /** A live-search result was chosen (click or keyboard). */
    onSelectProduct: (p: SearchProduct) => void;
    /** Enter / physical scanner: resolve the raw input as an exact barcode|SKU. */
    onScanSubmit: (raw: string) => Promise<AddResult>;
}

const TYPE_LABEL: Record<string, { label: string; variant: 'default' | 'info' | 'secondary' }> = {
    saleable_retail: { label: 'RETAIL', variant: 'secondary' },
    compoundable_drug: { label: 'RACIK', variant: 'default' },
    service: { label: 'JASA', variant: 'info' },
    service_with_consumption: { label: 'JASA', variant: 'info' },
};

export default function ProductSearchInput({ warehouseId, onSelectProduct, onScanSubmit }: Props) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<SearchProduct[]>([]);
    const [loading, setLoading] = useState(false);
    const [open, setOpen] = useState(false);
    const [highlight, setHighlight] = useState(-1);
    const containerRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLDivElement>(null);

    const term = q.trim();

    // One search call, shared by the live dropdown and the Enter handler.
    const runSearch = useCallback(
        (searchTerm: string): Promise<SearchProduct[]> =>
            axios
                .get(route('pos.search'), {
                    params: { q: searchTerm, warehouse_id: warehouseId },
                })
                .then((res) => res.data.results ?? []),
        [warehouseId],
    );

    // Debounced live search — fires while typing, no Enter required.
    useEffect(() => {
        if (term.length < 2) {
            setResults([]);
            setOpen(false);
            setHighlight(-1);
            return;
        }
        const t = setTimeout(async () => {
            setLoading(true);
            try {
                const hits = await runSearch(term);
                setResults(hits);
                setHighlight(-1);
                setOpen(true);
            } catch (err) {
                console.error('Gagal mencari produk', err);
                toast.error('Gagal mencari produk');
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 250);
        return () => clearTimeout(t);
    }, [term, runSearch]);

    // Close the dropdown when clicking outside the component.
    useEffect(() => {
        function onDocMouseDown(e: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', onDocMouseDown);
        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, []);

    // Keep the keyboard-highlighted row scrolled into view.
    useEffect(() => {
        if (highlight < 0) return;
        listRef.current
            ?.querySelector<HTMLElement>(`[data-idx="${highlight}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [highlight]);

    function reset() {
        setQ('');
        setResults([]);
        setOpen(false);
        setHighlight(-1);
    }

    function choose(p: SearchProduct) {
        onSelectProduct(p);
        reset();
        inputRef.current?.focus();
    }

    // Enter with no row highlighted. Priority:
    //   1. exact barcode/SKU match  -> add (physical scanner path)
    //   2. else, if the search has hits -> add the top one
    //   3. else -> "not found"
    async function submit() {
        if (!term) return;

        const result = await onScanSubmit(term);
        if (result === 'added') {
            reset();
            inputRef.current?.focus();
            return;
        }
        if (result === 'error') {
            inputRef.current?.focus();
            return;
        }

        // result === 'notfound' — fall through to the live-search hits.
        // Search now if the debounce hasn't landed yet (fast type + Enter).
        let hits = results;
        if (hits.length === 0) {
            try {
                hits = await runSearch(term);
                setResults(hits);
                setOpen(true);
            } catch (err) {
                console.error('Gagal mencari produk', err);
                toast.error('Gagal mencari produk');
                inputRef.current?.focus();
                return;
            }
        }
        if (hits.length > 0) {
            choose(hits[0]);
            return;
        }

        toast.error('Produk tidak ditemukan');
        inputRef.current?.focus();
    }

    function onKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'ArrowDown' && results.length) {
            e.preventDefault();
            setOpen(true);
            setHighlight((h) => (h + 1) % results.length);
        } else if (e.key === 'ArrowUp' && results.length) {
            e.preventDefault();
            setOpen(true);
            setHighlight((h) => (h <= 0 ? results.length - 1 : h - 1));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // Highlighted a row with the keyboard -> pick it. Otherwise treat
            // the raw input as a barcode (physical scanner: fast type + Enter).
            if (open && highlight >= 0 && results[highlight]) {
                choose(results[highlight]);
            } else {
                void submit();
            }
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    }

    return (
        <div ref={containerRef} className="relative flex-1">
            <Input
                ref={inputRef}
                autoFocus
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onKeyDown={onKeyDown}
                onFocus={() => {
                    if (term.length >= 2 && results.length > 0) setOpen(true);
                }}
                placeholder="Scan barcode / ketik nama produk…"
            />

            {open && term.length >= 2 && (
                <div className="absolute z-50 mt-1 w-full overflow-hidden rounded-md border bg-background shadow-lg">
                    <div ref={listRef} className="max-h-[50vh] overflow-y-auto">
                        {loading && results.length === 0 && (
                            <div className="p-4 text-center text-sm text-muted-foreground">
                                Mencari…
                            </div>
                        )}
                        {!loading && results.length === 0 && (
                            <div className="p-4 text-center text-sm text-muted-foreground">
                                Tidak ada produk yang cocok.
                            </div>
                        )}
                        <ul className="divide-y">
                            {results.map((p, i) => {
                                const meta = TYPE_LABEL[p.type] ?? {
                                    label: p.type,
                                    variant: 'secondary' as const,
                                };
                                return (
                                    <li key={p.id}>
                                        <button
                                            type="button"
                                            data-idx={i}
                                            onMouseEnter={() => setHighlight(i)}
                                            onClick={() => choose(p)}
                                            className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-left ${
                                                i === highlight ? 'bg-accent' : 'hover:bg-accent'
                                            }`}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="truncate font-medium">
                                                        {p.name}
                                                    </span>
                                                    <Badge
                                                        variant={meta.variant}
                                                        className="text-[10px]"
                                                    >
                                                        {meta.label}
                                                    </Badge>
                                                </div>
                                                <div className="truncate text-xs text-muted-foreground">
                                                    {p.sku}
                                                    {p.barcode ? ` · ${p.barcode}` : ''}
                                                </div>
                                            </div>
                                            <div className="text-right">
                                                <div className="font-semibold">
                                                    {rupiah(p.price)}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {p.is_service
                                                        ? 'jasa'
                                                        : `stok: ${p.stock_qty ?? 0}`}
                                                </div>
                                            </div>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </div>
            )}
        </div>
    );
}
