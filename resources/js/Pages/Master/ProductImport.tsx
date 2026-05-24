import { useState, type ChangeEvent } from 'react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';
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

interface PriceTier {
    id: number;
    name: string;
    sort_order: number;
    is_default: boolean;
    is_active: boolean;
}
interface CatItem { id: number; name: string }
interface UnitItem { id: number; code: string; name: string }

interface RowReport {
    row_num: number;
    sku: string;
    name?: string;
    action: 'insert' | 'update' | 'skip';
    errors: string[];
    warnings: string[];
}
interface PreviewResult {
    summary: { insert: number; update: number; skip: number; warnings: number };
    rows: RowReport[];
    fatal_errors: string[];
}

interface Props {
    tiers: PriceTier[];
    categories: CatItem[];
    units: UnitItem[];
}

const ACTION_BADGE: Record<RowReport['action'], { label: string; variant: 'success' | 'info' | 'destructive' }> = {
    insert: { label: 'INSERT', variant: 'success' },
    update: { label: 'UPDATE', variant: 'info' },
    skip: { label: 'SKIP', variant: 'destructive' },
};

export default function ProductImport({ tiers, categories, units }: Props) {
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<PreviewResult | null>(null);
    const [loading, setLoading] = useState(false);
    const [committing, setCommitting] = useState(false);
    const [committed, setCommitted] = useState(false);

    function handleFileChange(e: ChangeEvent<HTMLInputElement>) {
        const f = e.target.files?.[0] ?? null;
        setFile(f);
        setPreview(null);
        setCommitted(false);
    }

    async function downloadTemplate() {
        // Trigger native download — gunakan window.location supaya Set-Cookie session intact
        window.location.href = route('master.products.import.template');
    }

    async function runPreview() {
        if (! file) {
            toast.error('Pilih file dulu');
            return;
        }
        setLoading(true);
        try {
            const form = new FormData();
            form.append('file', file);
            const res = await axios.post<PreviewResult>(
                route('master.products.import.preview'),
                form,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            setPreview(res.data);
            setCommitted(false);
            if (res.data.fatal_errors.length > 0) {
                toast.error(res.data.fatal_errors[0]);
            } else {
                toast.success(`Preview: ${res.data.summary.insert} insert, ${res.data.summary.update} update, ${res.data.summary.skip} skip`);
            }
        } catch (e: unknown) {
            const msg = axios.isAxiosError(e) ? e.response?.data?.message ?? 'Gagal preview' : 'Gagal preview';
            toast.error(msg);
        } finally {
            setLoading(false);
        }
    }

    async function runCommit() {
        if (! file || ! preview) return;
        const willChange = preview.summary.insert + preview.summary.update;
        if (! confirm(
            `Konfirmasi import: ${preview.summary.insert} produk baru + ${preview.summary.update} update?\n`
            + `Total ${willChange} produk akan tersimpan. Aksi ini tidak bisa dibatalkan.`,
        )) return;

        setCommitting(true);
        try {
            const form = new FormData();
            form.append('file', file);
            const res = await axios.post<PreviewResult>(
                route('master.products.import.commit'),
                form,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            setPreview(res.data);
            setCommitted(true);
            toast.success(`Import berhasil: ${res.data.summary.insert} insert + ${res.data.summary.update} update`);
        } catch (e: unknown) {
            const msg = axios.isAxiosError(e) ? e.response?.data?.message ?? 'Gagal commit' : 'Gagal commit';
            toast.error(msg);
        } finally {
            setCommitting(false);
        }
    }

    const defaultTier = tiers.find((t) => t.is_default);
    const canCommit = preview
        && preview.fatal_errors.length === 0
        && (preview.summary.insert + preview.summary.update) > 0
        && ! committed;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold">Import Produk dari Excel</h2>
                    <Link href={route('master.products.index')} className="text-sm text-sky-700 hover:underline">
                        ← kembali ke Master Produk
                    </Link>
                </div>
            }
        >
            <Head title="Import Produk" />

            <div className="mx-auto max-w-6xl space-y-4 p-4">
                {/* Step 1: Download template */}
                <Card>
                    <CardContent className="space-y-3 p-4">
                        <h3 className="font-semibold">1. Download Template</h3>
                        <p className="text-sm text-muted-foreground">
                            Template Excel berisi header sesuai tier existing tenant (
                            {tiers.map((t) => t.name).join(', ')}
                            ) + sheet "Instruksi" dgn aturan, daftar kategori, & satuan terdaftar.
                            Default tier: <strong>{defaultTier?.name ?? '-'}</strong>.
                        </p>
                        <Button type="button" variant="outline" onClick={downloadTemplate}>
                            Download Template .xlsx
                        </Button>
                    </CardContent>
                </Card>

                {/* Step 2: Upload + preview */}
                <Card>
                    <CardContent className="space-y-3 p-4">
                        <h3 className="font-semibold">2. Upload + Preview</h3>
                        <p className="text-sm text-muted-foreground">
                            File akan di-parse + validasi, <strong>TIDAK</strong> ditulis ke database.
                            Ringkasan & error per baris muncul di bawah. Klik <em>Konfirmasi</em>
                            {' '}di Step 3 untuk apply.
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                type="file"
                                accept=".xlsx,.xls"
                                onChange={handleFileChange}
                                className="flex h-11 cursor-pointer rounded-md border border-input bg-background px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium"
                            />
                            <Button type="button" onClick={runPreview} disabled={! file || loading}>
                                {loading ? 'Memproses…' : 'Preview'}
                            </Button>
                        </div>
                        {file && (
                            <p className="text-xs text-muted-foreground">
                                File: <strong>{file.name}</strong> ({(file.size / 1024).toFixed(1)} KB)
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Preview results */}
                {preview && (
                    <>
                        {preview.fatal_errors.length > 0 ? (
                            <Card>
                                <CardContent className="p-4">
                                    <h3 className="mb-2 font-semibold text-red-700">Fatal Error</h3>
                                    <ul className="list-disc space-y-1 pl-5 text-sm text-red-700">
                                        {preview.fatal_errors.map((e, i) => <li key={i}>{e}</li>)}
                                    </ul>
                                </CardContent>
                            </Card>
                        ) : (
                            <>
                                {/* Summary card */}
                                <Card>
                                    <CardContent className="p-4">
                                        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                                            <SummaryBox label="Insert (baru)" count={preview.summary.insert} color="text-green-700" />
                                            <SummaryBox label="Update (existing)" count={preview.summary.update} color="text-sky-700" />
                                            <SummaryBox label="Skip (error)" count={preview.summary.skip} color="text-red-700" />
                                            <SummaryBox label="Warnings" count={preview.summary.warnings} color="text-amber-700" />
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Step 3: confirm commit */}
                                <Card>
                                    <CardContent className="space-y-3 p-4">
                                        <h3 className="font-semibold">3. Konfirmasi & Import</h3>
                                        <p className="text-sm text-muted-foreground">
                                            {committed
                                                ? '✓ Import sudah selesai. Lihat hasil di Master Produk.'
                                                : canCommit
                                                    ? 'Cek tabel di bawah dulu. Setelah yakin, klik tombol di bawah untuk apply ke database.'
                                                    : 'Belum ada baris valid yang bisa di-import.'}
                                        </p>
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                disabled={! canCommit || committing}
                                                onClick={runCommit}
                                            >
                                                {committing
                                                    ? 'Menyimpan…'
                                                    : `Konfirmasi & Import (${preview.summary.insert + preview.summary.update})`}
                                            </Button>
                                            {committed && (
                                                <Link href={route('master.products.index')}>
                                                    <Button type="button" variant="outline">
                                                        Lihat Master Produk →
                                                    </Button>
                                                </Link>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Per-row table */}
                                {preview.rows.length > 0 && (
                                    <Card>
                                        <CardContent className="p-0">
                                            <Table>
                                                <TableHeader>
                                                    <TableRow>
                                                        <TableHead className="w-16">Baris</TableHead>
                                                        <TableHead>Kode</TableHead>
                                                        <TableHead>Nama</TableHead>
                                                        <TableHead>Aksi</TableHead>
                                                        <TableHead>Catatan</TableHead>
                                                    </TableRow>
                                                </TableHeader>
                                                <TableBody>
                                                    {preview.rows.map((r) => {
                                                        const meta = ACTION_BADGE[r.action];
                                                        return (
                                                            <TableRow key={r.row_num}>
                                                                <TableCell className="font-mono text-xs">{r.row_num}</TableCell>
                                                                <TableCell className="font-mono text-xs">{r.sku || '-'}</TableCell>
                                                                <TableCell>{r.name ?? '-'}</TableCell>
                                                                <TableCell>
                                                                    <Badge variant={meta.variant}>{meta.label}</Badge>
                                                                </TableCell>
                                                                <TableCell>
                                                                    {r.errors.length > 0 && (
                                                                        <ul className="space-y-1 text-xs text-red-700">
                                                                            {r.errors.map((e, i) => <li key={i}>• {e}</li>)}
                                                                        </ul>
                                                                    )}
                                                                    {r.warnings.length > 0 && (
                                                                        <ul className="mt-1 space-y-1 text-xs text-amber-700">
                                                                            {r.warnings.map((w, i) => <li key={i}>⚠ {w}</li>)}
                                                                        </ul>
                                                                    )}
                                                                    {r.errors.length === 0 && r.warnings.length === 0 && (
                                                                        <span className="text-xs text-muted-foreground">—</span>
                                                                    )}
                                                                </TableCell>
                                                            </TableRow>
                                                        );
                                                    })}
                                                </TableBody>
                                            </Table>
                                        </CardContent>
                                    </Card>
                                )}
                            </>
                        )}
                    </>
                )}

                {/* Reference data summary */}
                <Card>
                    <CardContent className="space-y-2 p-4 text-xs text-muted-foreground">
                        <div>
                            <strong>Tier existing</strong> ({tiers.length}): {tiers.map((t) => `${t.name}${t.is_default ? '*' : ''}`).join(', ')}
                            {' '}— * = default
                        </div>
                        <div>
                            <strong>Kategori existing</strong> ({categories.length}): {categories.slice(0, 8).map((c) => c.name).join(', ')}
                            {categories.length > 8 && ` … +${categories.length - 8}`}
                        </div>
                        <div>
                            <strong>Satuan existing</strong> ({units.length}): {units.slice(0, 10).map((u) => u.code).join(', ')}
                            {units.length > 10 && ` … +${units.length - 10}`}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}

function SummaryBox({ label, count, color }: { label: string; count: number; color: string }) {
    return (
        <div className="rounded-md border p-3 text-center">
            <div className={`text-3xl font-bold ${color}`}>{count}</div>
            <div className="text-xs text-muted-foreground">{label}</div>
        </div>
    );
}
