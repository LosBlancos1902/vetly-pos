import { Head, Link } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { formatQty, rupiah } from '@/lib/utils';

interface ProductLite { id: number; sku: string; name: string }
interface UnitLite { id: number; code: string; name: string }

interface SaleItem {
    id: number;
    qty: string;
    price: string;
    discount_amount: string;
    subtotal: string;
    product: ProductLite | null;
    unit: UnitLite | null;
}
interface SalePayment {
    id: number;
    method: string;
    amount: string;
    reference_no: string | null;
}
interface PromoApplication {
    id: number;
    discount_amount: string;
    coa_code: string | null;
    promo: { id: number; name: string; type: string } | null;
}
interface Sale {
    id: number;
    invoice_no: string;
    date: string;
    status: string;
    payment_status: string;
    payment_method: string | null;
    subtotal: string;
    discount_amount: string;
    promo_discount_amount: string | null;
    tax_amount: string;
    total: string;
    amount_paid: string | null;
    change_amount: string;
    notes: string | null;
    items: SaleItem[];
    payments: SalePayment[];
    customer: { id: number; code: string; name: string } | null;
    warehouse: {
        id: number;
        code: string;
        name: string;
        address: string | null;
        phone: string | null;
        footer_override: string | null;
        warehouse_type: string;
    } | null;
    cashier: { id: number; name: string } | null;
    promo_applications: PromoApplication[];
}

interface Branding {
    brand_name: string | null;
    logo_data: string | null;
    footer_text: string | null;
    npwp: string | null;
    license_no: string | null;
}

interface Props {
    sale: Sale;
    width: '58mm' | '80mm';
    tenantName: string;
    branding: Branding;
    printedAt: string;
}

const METHOD_LABEL: Record<string, string> = {
    cash: 'TUNAI',
    transfer: 'TRANSFER',
    qris: 'QRIS',
    debit: 'DEBIT',
    credit: 'KREDIT',
    ewallet: 'E-WALLET',
    voucher: 'VOUCHER',
};

/**
 * Struk Penjualan — thermal-ready view.
 *
 * Lebar: 80mm default (toggle 58mm via ?width=58mm). Font monospace.
 * Layout struk klasik: header → info → items → ringkasan → footer.
 *
 * NILAI tampil persis dari data sale tersimpan — TIDAK ada hitung ulang.
 *
 * Print: tombol Print panggil window.print(). CSS @media print hide
 * navigation/tombol, paksa @page size sesuai lebar thermal.
 */
export default function Receipt({ sale, width, tenantName, branding, printedAt }: Props) {
    // 58mm ≈ 32 chars; 80mm ≈ 48 chars (perkiraan font 12px).
    const widthPx = width === '58mm' ? '58mm' : '80mm';
    const fontSize = width === '58mm' ? 'text-[10px]' : 'text-[11px]';

    function handlePrint() {
        window.print();
    }

    function formatDateTime(iso: string): string {
        const d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }

    const isVoid = sale.status === 'void';

    return (
        <>
            <Head title={`Struk ${sale.invoice_no}`} />

            {/* Print CSS — @page + hide non-receipt elements */}
            <style>{`
                @media print {
                    @page {
                        size: ${widthPx} auto;
                        margin: 2mm;
                    }
                    html, body {
                        background: #fff !important;
                        margin: 0 !important;
                        padding: 0 !important;
                    }
                    .no-print { display: none !important; }
                    .receipt-paper {
                        box-shadow: none !important;
                        border: 0 !important;
                        margin: 0 !important;
                        padding: 1mm !important;
                        width: ${widthPx} !important;
                        max-width: ${widthPx} !important;
                    }
                }
            `}</style>

            {/* Toolbar — non-print */}
            <div className="no-print sticky top-0 z-10 border-b border-gray-200 bg-white px-4 py-2">
                <div className="mx-auto flex max-w-4xl flex-wrap items-center gap-2">
                    <Link
                        href={route('sales.show', sale.id)}
                        className="rounded border border-gray-300 bg-white px-3 py-1 text-sm hover:bg-gray-50"
                    >
                        ← Detail
                    </Link>
                    <Link
                        href={route('sales.index')}
                        className="rounded border border-gray-300 bg-white px-3 py-1 text-sm hover:bg-gray-50"
                    >
                        Riwayat Penjualan
                    </Link>
                    <div className="ml-auto flex items-center gap-2">
                        <span className="text-xs text-gray-500">Lebar:</span>
                        <Link
                            href={route('sales.receipt', sale.id) + '?width=58mm'}
                            className={
                                'rounded border px-2 py-1 text-xs ' +
                                (width === '58mm'
                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                    : 'border-gray-300 bg-white')
                            }
                        >
                            58mm
                        </Link>
                        <Link
                            href={route('sales.receipt', sale.id) + '?width=80mm'}
                            className={
                                'rounded border px-2 py-1 text-xs ' +
                                (width === '80mm'
                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                    : 'border-gray-300 bg-white')
                            }
                        >
                            80mm
                        </Link>
                        <Button type="button" size="sm" onClick={handlePrint}>
                            Cetak
                        </Button>
                    </div>
                </div>
            </div>

            {/* Page background — non-print */}
            <div className="no-print min-h-screen bg-gray-100 py-6">
                <div className="mx-auto flex justify-center">
                    <ReceiptPaper
                        sale={sale}
                        tenantName={tenantName}
                        branding={branding}
                        widthPx={widthPx}
                        fontSize={fontSize}
                        formatDateTime={formatDateTime}
                        printedAt={printedAt}
                        isVoid={isVoid}
                    />
                </div>
            </div>

            {/* Print-only render (so layout is paper-only without bg/toolbar) */}
            <div className="hidden print:block">
                <ReceiptPaper
                    sale={sale}
                    tenantName={tenantName}
                    branding={branding}
                    widthPx={widthPx}
                    fontSize={fontSize}
                    formatDateTime={formatDateTime}
                    printedAt={printedAt}
                    isVoid={isVoid}
                />
            </div>
        </>
    );
}

function ReceiptPaper({
    sale,
    tenantName,
    branding,
    widthPx,
    fontSize,
    formatDateTime,
    printedAt,
    isVoid,
}: {
    sale: Sale;
    tenantName: string;
    branding: Branding;
    widthPx: string;
    fontSize: string;
    formatDateTime: (iso: string) => string;
    printedAt: string;
    isVoid: boolean;
}) {
    const wh = sale.warehouse;
    const customer = sale.customer;
    const promoTotal = Number(sale.promo_discount_amount ?? 0);
    const manualDiscount = Number(sale.discount_amount);

    // Header brand: branding.brand_name → tenant name (fallback).
    // Cabang ditampilkan terpisah (warehouse name) supaya tetap jelas
    // walaupun brand_name diset.
    const brandLine = (branding.brand_name?.trim() || tenantName).toUpperCase();
    // Footer: warehouse override (kalau ada) → tenant footer_text → fallback teks default.
    const footerText = (wh?.footer_override?.trim() || branding.footer_text?.trim() || 'Terima kasih atas kunjungan Anda');

    return (
        <div
            className={
                'receipt-paper border border-gray-300 bg-white p-3 font-mono leading-snug ' +
                fontSize
            }
            style={{ width: widthPx, maxWidth: widthPx }}
        >
            {isVoid && (
                <div className="mb-2 border-2 border-red-600 py-1 text-center text-sm font-bold text-red-600">
                    *** VOID / DIBATALKAN ***
                </div>
            )}

            {/* HEADER */}
            <div className="text-center">
                {branding.logo_data && (
                    <div className="mb-1 flex justify-center">
                        <img
                            src={branding.logo_data}
                            alt="logo"
                            className="max-h-16 max-w-full object-contain"
                        />
                    </div>
                )}
                <div className="text-sm font-bold uppercase">{brandLine}</div>
                {wh?.name && wh.name.toUpperCase() !== brandLine && (
                    <div className="text-[11px] font-semibold">{wh.name}</div>
                )}
                {wh?.address && <div className="whitespace-pre-line">{wh.address}</div>}
                {wh?.phone && <div className="text-[10px]">Telp: {wh.phone}</div>}
                {wh?.code && (
                    <div className="text-[10px] text-gray-700">Cabang: {wh.code}</div>
                )}
                {branding.npwp && (
                    <div className="text-[10px] text-gray-700">NPWP: {branding.npwp}</div>
                )}
                {branding.license_no && (
                    <div className="text-[10px] text-gray-700">Izin: {branding.license_no}</div>
                )}
            </div>

            <Divider />

            {/* INFO TRANSAKSI */}
            <KV k="No" v={sale.invoice_no} />
            <KV k="Tgl" v={formatDateTime(sale.date)} />
            <KV k="Kasir" v={sale.cashier?.name ?? '-'} />
            <KV k="Pelanggan" v={customer?.name ?? 'Umum'} />

            <Divider />

            {/* ITEMS */}
            {sale.items.map((it) => {
                const qtyStr = formatQty(it.qty, 4);
                const unitCode = it.unit?.code ?? '';
                const priceStr = rupiah(it.price);
                const subStr = rupiah(it.subtotal);
                const itemDisc = Number(it.discount_amount);
                return (
                    <div key={it.id} className="mb-1">
                        <div className="break-words">{it.product?.name ?? '#' + it.id}</div>
                        <div className="flex justify-between">
                            <span>
                                {qtyStr}
                                {unitCode ? ` ${unitCode}` : ''} × {priceStr}
                            </span>
                            <span>{subStr}</span>
                        </div>
                        {itemDisc > 0 && (
                            <div className="flex justify-between text-[10px] text-gray-600">
                                <span>  diskon item</span>
                                <span>−{rupiah(itemDisc)}</span>
                            </div>
                        )}
                    </div>
                );
            })}

            <Divider />

            {/* RINGKASAN */}
            <KV k="Subtotal" v={rupiah(sale.subtotal)} />
            {manualDiscount > 0 && (
                <KV k="Diskon Manual" v={'−' + rupiah(manualDiscount)} />
            )}

            {/* Promo applications — break down per promo */}
            {sale.promo_applications.length > 0 && (
                <>
                    {sale.promo_applications.map((pa) => (
                        <KV
                            key={pa.id}
                            k={`  ${pa.promo?.name ?? 'Promo'}`}
                            v={'−' + rupiah(pa.discount_amount)}
                        />
                    ))}
                    <KV k="Total Diskon Promo" v={'−' + rupiah(promoTotal)} />
                </>
            )}

            {Number(sale.tax_amount) > 0 && (
                <KV k="Pajak" v={rupiah(sale.tax_amount)} />
            )}

            <div className="my-1 border-t border-dashed border-gray-700" />
            <div className="flex justify-between text-sm font-bold">
                <span>TOTAL</span>
                <span>{rupiah(sale.total)}</span>
            </div>
            <div className="my-1 border-t border-dashed border-gray-700" />

            {/* PEMBAYARAN */}
            {sale.payments.length > 0 ? (
                sale.payments.map((p) => (
                    <KV
                        key={p.id}
                        k={METHOD_LABEL[p.method] ?? p.method.toUpperCase()}
                        v={rupiah(p.amount)}
                    />
                ))
            ) : (
                <KV
                    k={
                        METHOD_LABEL[sale.payment_method ?? 'cash']
                        ?? (sale.payment_method ?? 'TUNAI').toUpperCase()
                    }
                    v={rupiah(sale.amount_paid ?? sale.total)}
                />
            )}

            {sale.amount_paid !== null && (
                <KV k="Uang Diterima" v={rupiah(sale.amount_paid)} />
            )}
            {Number(sale.change_amount) > 0 && (
                <KV k="Kembalian" v={rupiah(sale.change_amount)} />
            )}

            {sale.notes && (
                <>
                    <Divider />
                    <div className="text-[10px] italic">Catatan: {sale.notes}</div>
                </>
            )}

            <Divider />

            {/* FOOTER */}
            <div className="text-center text-[10px]">
                <div className="whitespace-pre-line">{footerText}</div>
                <div className="mt-1 text-gray-500">
                    Dicetak: {formatDateTime(printedAt)}
                </div>
            </div>
        </div>
    );
}

function KV({ k, v }: { k: string; v: string }) {
    return (
        <div className="flex justify-between gap-2">
            <span className="whitespace-pre">{k}</span>
            <span className="text-right">{v}</span>
        </div>
    );
}

function Divider() {
    return <div className="my-1 border-t border-dashed border-gray-500" />;
}
