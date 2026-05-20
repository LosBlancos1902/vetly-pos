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
 * Format a quantity string (e.g. "1500.0000") cleanly: up to 4 fractional
 * digits, trailing zeros dropped, id-ID locale (1.500 not 1,500).
 */
export function formatQty(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '0';
    const n = typeof value === 'string' ? parseFloat(value) : value;
    if (isNaN(n)) return '0';
    return new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 4,
    }).format(n);
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
