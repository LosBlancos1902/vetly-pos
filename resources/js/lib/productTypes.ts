/**
 * Single source of truth utk label & tooltip "Jenis Produk" di UI.
 *
 * VALUE (slug) MATCH Product::TYPE_* di backend — JANGAN diubah, karena
 * dipakai DB enum + StockGuard + JournalEngine + ServiceBundle. Label di
 * sini murni tampilan FE — boleh dirubah kapan pun tanpa risiko ke jurnal.
 */

export type ProductTypeValue =
    | 'saleable_retail'
    | 'compoundable_drug'
    | 'raw_material'
    | 'service'
    | 'service_with_consumption';

export interface ProductTypeOption {
    value: ProductTypeValue;
    label: string;
    description: string;
}

/** Urutan ditampilkan di dropdown form (yang paling umum di-atas). */
export const PRODUCT_TYPES: ProductTypeOption[] = [
    {
        value: 'saleable_retail',
        label: 'Barang',
        description: 'Barang jadi yang dijual utuh (pet food, aksesori, obat kemasan).',
    },
    {
        value: 'compoundable_drug',
        label: 'Obat Racikan',
        description: 'Obat hasil racikan apoteker, dijual setelah diracik.',
    },
    {
        value: 'raw_material',
        label: 'Bahan Baku',
        description: 'Bahan untuk racikan atau jasa. Tidak dijual langsung di kasir.',
    },
    {
        value: 'service',
        label: 'Jasa',
        description: 'Layanan tanpa pakai bahan (konsultasi, grooming basic).',
    },
    {
        value: 'service_with_consumption',
        label: 'Jasa + Bahan',
        description: 'Jasa yang sambil pakai bahan (vaksinasi, sterilisasi).',
    },
];

export const PRODUCT_TYPE_LABEL: Record<string, string> = Object.fromEntries(
    PRODUCT_TYPES.map((t) => [t.value, t.label]),
);

export const PRODUCT_TYPE_DESCRIPTION: Record<string, string> = Object.fromEntries(
    PRODUCT_TYPES.map((t) => [t.value, t.description]),
);

/** Label aman utk render — fallback ke value asli kalau slug tak dikenal. */
export function productTypeLabel(value: string | null | undefined): string {
    if (!value) return '-';
    return PRODUCT_TYPE_LABEL[value] ?? value;
}
