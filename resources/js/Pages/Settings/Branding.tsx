import { useRef, useState, type FormEvent } from 'react';
import { Head, router } from '@inertiajs/react';
import { toast } from 'sonner';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent } from '@/Components/ui/card';
import { Textarea } from '@/Components/ui/textarea';
import { Badge } from '@/Components/ui/badge';

interface Branding {
    brand_name: string | null;
    logo_data: string | null;   // data:image/...;base64,...
    logo_mime: string | null;
    footer_text: string | null;
    npwp: string | null;
    license_no: string | null;
}

interface WarehouseRow {
    id: number;
    code: string;
    name: string;
    warehouse_type: string;
    address: string | null;
    phone: string | null;
    footer_override: string | null;
    is_active: boolean;
    is_default: boolean;
}

interface Props {
    branding: Branding;
    warehouses: WarehouseRow[];
    tenantName: string;
    logoMaxKb: number;
}

export default function Branding({ branding, warehouses, tenantName, logoMaxKb }: Props) {
    // ──────────────────────────── Tenant form ────────────────────────────
    const [tForm, setTForm] = useState({
        brand_name: branding.brand_name ?? '',
        footer_text: branding.footer_text ?? '',
        npwp: branding.npwp ?? '',
        license_no: branding.license_no ?? '',
        remove_logo: false,
    });
    const fileRef = useRef<HTMLInputElement>(null);
    const [logoPreview, setLogoPreview] = useState<string | null>(branding.logo_data);

    function onLogoChange(e: React.ChangeEvent<HTMLInputElement>) {
        const f = e.target.files?.[0];
        if (!f) {
            setLogoPreview(branding.logo_data);
            return;
        }
        if (f.size > logoMaxKb * 1024) {
            toast.error(`Logo melebihi ${logoMaxKb} KB`);
            e.target.value = '';
            return;
        }
        const reader = new FileReader();
        reader.onload = () => setLogoPreview(reader.result as string);
        reader.readAsDataURL(f);
        setTForm({ ...tForm, remove_logo: false });
    }

    function submitTenant(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData();
        fd.append('brand_name', tForm.brand_name);
        fd.append('footer_text', tForm.footer_text);
        fd.append('npwp', tForm.npwp);
        fd.append('license_no', tForm.license_no);
        if (tForm.remove_logo) {
            fd.append('remove_logo', '1');
        } else if (fileRef.current?.files?.[0]) {
            fd.append('logo', fileRef.current.files[0]);
        }

        router.post(route('settings.branding.update_tenant'), fd, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Branding tenant diperbarui');
                if (fileRef.current) fileRef.current.value = '';
                setTForm({ ...tForm, remove_logo: false });
            },
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal menyimpan'),
        });
    }

    function removeLogo() {
        setLogoPreview(null);
        setTForm({ ...tForm, remove_logo: true });
        if (fileRef.current) fileRef.current.value = '';
    }

    // ──────────────────────── Warehouse form (modal-less) ────────────────────────
    const [whForms, setWhForms] = useState<Record<number, { address: string; phone: string; footer_override: string }>>(
        () => Object.fromEntries(warehouses.map((w) => [w.id, {
            address: w.address ?? '',
            phone: w.phone ?? '',
            footer_override: w.footer_override ?? '',
        }])),
    );

    function submitWarehouse(w: WarehouseRow, e: FormEvent) {
        e.preventDefault();
        const payload = whForms[w.id];
        router.put(route('settings.branding.update_warehouse', w.id), payload, {
            preserveScroll: true,
            onSuccess: () => toast.success(`Cabang '${w.name}' diperbarui`),
            onError: (errs) => toast.error(Object.values(errs)[0] ?? 'Gagal menyimpan'),
        });
    }

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold">Branding Struk</h2>}
        >
            <Head title="Branding Struk" />

            <div className="mx-auto max-w-5xl space-y-6 p-4">
                <Card>
                    <CardContent className="p-4 text-sm text-gray-600">
                        <p>
                            Pengaturan tampilan struk: <b>tenant-level</b> (nama brand, logo, footer, NPWP — berlaku
                            semua cabang) dan <b>per-cabang</b> (alamat, no telp, override footer).
                            Perubahan hanya mempengaruhi struk yang dicetak/dilihat ke depan — data transaksi
                            yang tersimpan tidak diubah.
                        </p>
                    </CardContent>
                </Card>

                {/* ────────── TENANT-LEVEL ────────── */}
                <Card>
                    <CardContent className="space-y-4 p-6">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-medium">Brand Tenant</h3>
                            <Badge variant="outline">Berlaku semua cabang</Badge>
                        </div>

                        <form onSubmit={submitTenant} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="brand_name">Nama Brand / Toko</Label>
                                    <Input
                                        id="brand_name"
                                        value={tForm.brand_name}
                                        onChange={(e) => setTForm({ ...tForm, brand_name: e.target.value })}
                                        placeholder={tenantName}
                                        maxLength={120}
                                    />
                                    <p className="mt-1 text-xs text-gray-500">
                                        Kosong → fallback ke nama tenant ({tenantName}).
                                    </p>
                                </div>
                                <div>
                                    <Label htmlFor="npwp">NPWP (opsional)</Label>
                                    <Input
                                        id="npwp"
                                        value={tForm.npwp}
                                        onChange={(e) => setTForm({ ...tForm, npwp: e.target.value })}
                                        maxLength={50}
                                    />
                                </div>
                                <div className="sm:col-span-2">
                                    <Label htmlFor="license_no">No Izin Usaha (opsional)</Label>
                                    <Input
                                        id="license_no"
                                        value={tForm.license_no}
                                        onChange={(e) => setTForm({ ...tForm, license_no: e.target.value })}
                                        maxLength={100}
                                    />
                                </div>
                                <div className="sm:col-span-2">
                                    <Label htmlFor="footer_text">Footer Struk (default)</Label>
                                    <Textarea
                                        id="footer_text"
                                        value={tForm.footer_text}
                                        onChange={(e) => setTForm({ ...tForm, footer_text: e.target.value })}
                                        placeholder="Terima kasih atas kunjungan Anda"
                                        rows={3}
                                        maxLength={500}
                                    />
                                    <p className="mt-1 text-xs text-gray-500">
                                        Cabang bisa override pesan ini.
                                    </p>
                                </div>
                            </div>

                            {/* Logo */}
                            <div className="border-t pt-4">
                                <Label>Logo</Label>
                                <div className="mt-2 flex flex-wrap items-start gap-4">
                                    <div className="flex h-32 w-32 items-center justify-center rounded border border-dashed border-gray-300 bg-gray-50 p-2">
                                        {logoPreview ? (
                                            <img
                                                src={logoPreview}
                                                alt="logo"
                                                className="max-h-full max-w-full object-contain"
                                            />
                                        ) : (
                                            <span className="text-xs text-gray-400">No logo</span>
                                        )}
                                    </div>
                                    <div className="flex-1 space-y-2">
                                        <Input
                                            type="file"
                                            ref={fileRef}
                                            accept="image/png,image/jpeg,image/gif,image/svg+xml"
                                            onChange={onLogoChange}
                                        />
                                        <p className="text-xs text-gray-500">
                                            PNG / JPG / GIF / SVG. Maks {logoMaxKb} KB.
                                            Rekomendasi: monokrom, ≤ 200 × 80 px untuk thermal.
                                        </p>
                                        {(branding.logo_data || logoPreview) && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={removeLogo}
                                            >
                                                Hapus Logo
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="flex justify-end">
                                <Button type="submit">Simpan Branding Tenant</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                {/* ────────── PER-WAREHOUSE ────────── */}
                <Card>
                    <CardContent className="space-y-4 p-6">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-medium">Alamat & Footer per Cabang</h3>
                            <Badge variant="outline">{warehouses.length} cabang</Badge>
                        </div>

                        <div className="space-y-4">
                            {warehouses.map((w) => (
                                <form
                                    key={w.id}
                                    onSubmit={(e) => submitWarehouse(w, e)}
                                    className="rounded border border-gray-200 p-4"
                                >
                                    <div className="mb-3 flex items-center gap-2">
                                        <span className="font-medium">{w.name}</span>
                                        <Badge variant="secondary" className="text-xs">{w.code}</Badge>
                                        {w.is_default && (
                                            <Badge className="text-xs">Default</Badge>
                                        )}
                                        {!w.is_active && (
                                            <Badge variant="destructive" className="text-xs">Nonaktif</Badge>
                                        )}
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="sm:col-span-2">
                                            <Label htmlFor={`addr-${w.id}`}>Alamat</Label>
                                            <Textarea
                                                id={`addr-${w.id}`}
                                                value={whForms[w.id].address}
                                                onChange={(e) => setWhForms({
                                                    ...whForms,
                                                    [w.id]: { ...whForms[w.id], address: e.target.value },
                                                })}
                                                rows={2}
                                                maxLength={500}
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor={`phone-${w.id}`}>No Telp</Label>
                                            <Input
                                                id={`phone-${w.id}`}
                                                value={whForms[w.id].phone}
                                                onChange={(e) => setWhForms({
                                                    ...whForms,
                                                    [w.id]: { ...whForms[w.id], phone: e.target.value },
                                                })}
                                                maxLength={30}
                                            />
                                        </div>
                                        <div className="sm:col-span-2">
                                            <Label htmlFor={`fo-${w.id}`}>
                                                Footer Override (opsional — kosong = pakai footer tenant)
                                            </Label>
                                            <Textarea
                                                id={`fo-${w.id}`}
                                                value={whForms[w.id].footer_override}
                                                onChange={(e) => setWhForms({
                                                    ...whForms,
                                                    [w.id]: { ...whForms[w.id], footer_override: e.target.value },
                                                })}
                                                rows={2}
                                                maxLength={500}
                                            />
                                        </div>
                                    </div>

                                    <div className="mt-3 flex justify-end">
                                        <Button type="submit" size="sm">Simpan Cabang</Button>
                                    </div>
                                </form>
                            ))}

                            {warehouses.length === 0 && (
                                <div className="text-center text-sm text-gray-500">
                                    Belum ada cabang.
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
