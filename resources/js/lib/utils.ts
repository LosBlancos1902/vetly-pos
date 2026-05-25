import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/** Format a number as Indonesian Rupiah. */
export function rupiah(value: number | string): string {
    const n = typeof value === 'string' ? parseFloat(value) : value;
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: 0,
    }).format(isNaN(n) ? 0 : n);
}

/**
 * Format a quantity string (e.g. "1500.0000") cleanly: up to 2 fractional
 * digits by default, trailing zeros dropped, id-ID locale (1.500 not 1,500).
 *
 * DB column is DECIMAL(15,4) by design (untuk resep racik yg butuh presisi
 * tinggi), tapi stok display default 2 desimal — "0,0002 vial" hampir
 * selalu typo, bukan reading yg valid. Pass `maxDigits` 4 kalau memang
 * butuh tampilkan presisi penuh (mis. recipe component qty).
 */
export function formatQty(value: number | string | null | undefined, maxDigits = 2): string {
    if (value === null || value === undefined || value === '') return '0';
    const n = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(n)) return '0';
    return new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: maxDigits,
    }).format(n);
}

/**
 * Money for an <input type="number"> field: strip ALL decimals.
 * DB column tetap DECIMAL(15,2/4) presisi penuh — ini cuma tampilan input.
 * "10000.0000" → "10000", "12500.50" → "12501" (rounded).
 * Rupiah jarang banget butuh sub-rupiah di UI; bulatan hanya di display layer.
 */
export function inputMoney(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';
    const n = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(n)) return '';
    return Math.round(n).toString();
}

/**
 * Qty/ratio for an <input type="number"> field: strip TRAILING zeros, keep precision.
 * "10.0000" → "10", "0.0300" → "0.03", "1.5000" → "1.5". Max 4 fractional digits
 * (matches DECIMAL(15,4) DB precision). Pakai untuk qty, rasio konversi, percent.
 */
export function inputQty(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '';
    const n = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(n)) return '';
    return parseFloat(n.toFixed(4)).toString();
}

/** Format an ISO/datetime string as Indonesian datetime: "20 Mei 2026 14:30". */
export function formatDateID(value: string | Date | null | undefined): string {
    if (!value) return '-';
    const d = typeof value === 'string' ? new Date(value) : value;
    if (isNaN(d.getTime())) return '-';
    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(d);
}
