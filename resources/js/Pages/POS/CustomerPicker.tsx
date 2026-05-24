import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export interface PickerCustomer {
    id: number;
    code: string;
    name: string;
    phone: string | null;
    email?: string | null;
}

interface Props {
    selected: PickerCustomer | null;
    onChange: (c: PickerCustomer | null) => void;
}

/**
 * Live search + quick-create dropdown utk POS Cashier.
 * Search by phone/name. Klik hasil → set selected. Klik "Walk-in/Umum"
 * → clear. Tombol "+ Baru" → modal quick-create (lebih ringkas dari
 * full form di Master Pelanggan).
 */
export default function CustomerPicker({ selected, onChange }: Props) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<PickerCustomer[]>([]);
    const [open, setOpen] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        function onClick(e: MouseEvent) {
            if (containerRef.current && ! containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', onClick);

        return () => document.removeEventListener('mousedown', onClick);
    }, []);

    const search = useCallback(async (term: string) => {
        if (term.trim().length < 2) {
            setResults([]);

            return;
        }
        try {
            const res = await axios.get<{ results: PickerCustomer[] }>(
                route('master.customers.search'),
                { params: { q: term } },
            );
            setResults(res.data.results);
        } catch {
            setResults([]);
        }
    }, []);

    // Debounce
    useEffect(() => {
        const id = setTimeout(() => search(q), 250);

        return () => clearTimeout(id);
    }, [q, search]);

    function pick(c: PickerCustomer) {
        onChange(c);
        setQ('');
        setResults([]);
        setOpen(false);
    }

    function clear() {
        onChange(null);
        setQ('');
        setOpen(false);
    }

    return (
        <div ref={containerRef} className="relative">
            {selected ? (
                <div className="flex items-center gap-2 rounded-md border bg-background px-3 py-2 text-sm">
                    <span className="font-medium">{selected.name}</span>
                    {selected.phone && (
                        <span className="text-xs text-muted-foreground">· {selected.phone}</span>
                    )}
                    <button
                        type="button"
                        onClick={clear}
                        className="ml-auto text-muted-foreground hover:text-destructive"
                        title="Ganti ke walk-in"
                    >
                        ✕
                    </button>
                </div>
            ) : (
                <div className="flex gap-1">
                    <Input
                        value={q}
                        onChange={(e) => { setQ(e.target.value); setOpen(true); }}
                        onFocus={() => setOpen(true)}
                        placeholder="Cari pelanggan (HP / nama) — kosong = Umum"
                        className="flex-1"
                    />
                    <Button type="button" variant="outline" size="sm"
                        onClick={() => setCreateOpen(true)}
                        title="Tambah pelanggan baru">
                        + Baru
                    </Button>
                </div>
            )}

            {open && ! selected && q.trim().length >= 2 && (
                <div className="absolute z-50 mt-1 w-full rounded-md border bg-popover shadow-lg">
                    {results.length === 0 ? (
                        <div className="p-3 text-sm text-muted-foreground">
                            Tidak ada hasil. Klik <strong>+ Baru</strong> untuk buat pelanggan baru.
                        </div>
                    ) : (
                        <ul className="max-h-72 overflow-y-auto">
                            {results.map((c) => (
                                <li key={c.id}>
                                    <button
                                        type="button"
                                        onClick={() => pick(c)}
                                        className="flex w-full items-center justify-between p-2 text-left text-sm hover:bg-muted/50"
                                    >
                                        <div>
                                            <div className="font-medium">{c.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {c.phone ?? '—'} · {c.code}
                                            </div>
                                        </div>
                                        <Badge variant="muted" className="text-[10px]">{c.code}</Badge>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            <QuickCreateDialog
                open={createOpen}
                onClose={() => setCreateOpen(false)}
                initialQuery={q}
                onCreated={(c) => {
                    pick(c);
                    setCreateOpen(false);
                    toast.success(`Pelanggan ${c.name} dibuat`);
                }}
            />
        </div>
    );
}

function QuickCreateDialog({
    open,
    onClose,
    initialQuery,
    onCreated,
}: {
    open: boolean;
    onClose: () => void;
    initialQuery: string;
    onCreated: (c: PickerCustomer) => void;
}) {
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (open) {
            // Prefill: kalau query terlihat angka → masuk phone, kalau huruf → name.
            const looksLikePhone = /^[+\d\s-]+$/.test(initialQuery.trim());
            if (looksLikePhone) {
                setPhone(initialQuery.trim());
                setName('');
            } else {
                setName(initialQuery.trim());
                setPhone('');
            }
            setSubmitting(false);
        }
    }, [open, initialQuery]);

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (! name.trim() || ! phone.trim()) return;
        setSubmitting(true);
        try {
            const res = await axios.post<{ customer: PickerCustomer }>(
                route('master.customers.quick_store'),
                { name: name.trim(), phone: phone.trim() },
            );
            onCreated(res.data.customer);
        } catch (e: unknown) {
            const msg = axios.isAxiosError(e) && e.response?.status === 422
                ? Object.values(e.response.data?.errors ?? {})[0]?.toString() ?? 'Validasi gagal'
                : 'Gagal buat pelanggan';
            toast.error(msg);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={(o) => ! submitting && ! o && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Pelanggan Baru</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-3">
                    <div>
                        <Label htmlFor="qc-name">Nama *</Label>
                        <Input id="qc-name" value={name} autoFocus
                            onChange={(e) => setName(e.target.value)} required maxLength={255} />
                    </div>
                    <div>
                        <Label htmlFor="qc-phone">No HP *</Label>
                        <Input id="qc-phone" type="tel" value={phone}
                            onChange={(e) => setPhone(e.target.value)} required maxLength={32}
                            placeholder="08xxxx" />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Detail lain (email, alamat, dll) bisa dilengkapi nanti di Master Pelanggan.
                    </p>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose} disabled={submitting}>
                            Batal
                        </Button>
                        <Button type="submit" disabled={submitting || ! name.trim() || ! phone.trim()}>
                            {submitting ? 'Menyimpan…' : 'Buat & Pilih'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
