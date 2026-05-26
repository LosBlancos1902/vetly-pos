<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * Helper utility untuk export Excel dengan pilih kolom (customizable).
 *
 * Source of truth kolom didefinisikan oleh controller sebagai associative array:
 *   $cols = [
 *     'invoice_no' => ['label' => 'No Invoice', 'default' => true,  'value' => fn($r) => $r->invoice_no],
 *     'date'       => ['label' => 'Tanggal',    'default' => true,  'value' => fn($r) => $r->date],
 *     'ref_type'   => ['label' => 'Ref Type',   'default' => false, 'value' => fn($r) => $r->ref_type ?? ''],
 *   ];
 *
 * Klien (modal pilih-kolom) cuma butuh key + label + default — dikirim via
 * Inertia prop tanpa value extractor. Server-side validate selected keys
 * (anti-tamper: key di luar whitelist diabaikan).
 */
class ColumnPicker
{
    /**
     * Filter $availableColumns berdasarkan key yang dipilih klien.
     * Kalau $selectedKeys null/empty atau semua invalid → fallback ke default
     * columns (default=true). Kalau tidak ada default → fallback ke semua.
     *
     * Kembalikan tuple:
     *   - labels: list<string>  (header row 1)
     *   - extractors: list<callable> (value extractor per kolom, urut sama)
     *
     * @param  array<string, array{label:string, default?:bool, value:callable}>  $availableColumns
     * @param  array<int, string>|null  $selectedKeys
     * @return array{0: list<string>, 1: list<callable>}
     */
    public static function pick(array $availableColumns, ?array $selectedKeys): array
    {
        $keys = self::resolveKeys($availableColumns, $selectedKeys);
        $labels = [];
        $extractors = [];
        foreach ($keys as $k) {
            $labels[] = $availableColumns[$k]['label'];
            $extractors[] = $availableColumns[$k]['value'];
        }

        return [$labels, $extractors];
    }

    /**
     * Transform iterable of rows menjadi array of array values berdasarkan
     * extractors. Format flat: 1 baris = 1 record, urutan kolom sesuai
     * extractor order.
     *
     * @param  iterable<mixed>  $rows
     * @param  list<callable>  $extractors
     * @return list<list<mixed>>
     */
    public static function rowsToArray(iterable $rows, array $extractors): array
    {
        $out = [];
        foreach ($rows as $r) {
            $line = [];
            foreach ($extractors as $fn) {
                $line[] = $fn($r);
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Public-safe metadata untuk dikirim ke FE (modal pilih-kolom).
     * value extractor tidak ikut — cuma key+label+default.
     *
     * @param  array<string, array{label:string, default?:bool, value:callable}>  $availableColumns
     * @return list<array{key:string, label:string, default:bool}>
     */
    public static function publicMeta(array $availableColumns): array
    {
        $out = [];
        foreach ($availableColumns as $key => $cfg) {
            $out[] = [
                'key' => $key,
                'label' => $cfg['label'],
                'default' => (bool) ($cfg['default'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{label:string, default?:bool, value:callable}>  $available
     * @param  array<int, string>|null  $selected
     * @return list<string>
     */
    private static function resolveKeys(array $available, ?array $selected): array
    {
        if ($selected !== null && $selected !== []) {
            // Whitelist: hanya keys yang valid dipertahankan, urutan dari klien.
            $filtered = array_values(array_filter(
                $selected,
                fn ($k) => is_string($k) && array_key_exists($k, $available),
            ));
            if ($filtered !== []) {
                return $filtered;
            }
        }

        // Fallback: kolom dengan default=true (urutan sesuai definisi).
        $defaults = array_keys(array_filter(
            $available,
            fn ($c) => ($c['default'] ?? false) === true,
        ));
        if ($defaults !== []) {
            return $defaults;
        }

        // Fallback terakhir: semua kolom.
        return array_keys($available);
    }
}
