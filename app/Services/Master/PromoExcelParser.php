<?php

declare(strict_types=1);

namespace App\Services\Master;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parser file Excel daftar produk untuk Promo Per-Barang.
 *
 * Format yang diharapkan (sheet pertama):
 *   - Kolom A: sku
 *   - Kolom B (opsional): nama referensi
 *   - Baris 1 boleh header (auto-skip kalau A1 berisi 'sku' / 'kode' /
 *     'product' / 'produk') atau langsung data.
 *
 * Output: list ['row_excel' => int, 'sku' => string, 'name_ref' => ?string].
 * Dedup case-insensitive (SKU sama hanya muncul sekali, baris pertamanya
 * yang dipertahankan).
 */
class PromoExcelParser
{
    /** Hard cap supaya satu file gak ngabisin memory. */
    public const MAX_ROWS = 5000;

    /**
     * @return array{
     *   rows: list<array{row_excel:int, sku:string, name_ref:?string}>,
     *   total_raw: int,
     *   dedup_skipped: int,
     *   empty_skipped: int,
     *   truncated: bool
     * }
     */
    public function parse(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);
        // numeric indexes 0-based, null untuk cell kosong
        $rows = $sheet->toArray(null, true, true, false);

        $hasHeader = $this->looksLikeHeader($rows[0][0] ?? null);
        if ($hasHeader) {
            array_shift($rows);
            $startRowExcel = 2;
        } else {
            $startRowExcel = 1;
        }

        $out = [];
        $seenUpper = []; // sku UPPERCASE → first row_excel where seen
        $dedupSkipped = 0;
        $emptySkipped = 0;
        $totalRaw = 0;
        $truncated = false;

        foreach ($rows as $idx => $r) {
            if (count($out) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            $rowExcel = $startRowExcel + $idx;
            $skuRaw = isset($r[0]) ? trim((string) $r[0]) : '';
            $nameRaw = isset($r[1]) ? trim((string) $r[1]) : '';

            if ($skuRaw === '') {
                $emptySkipped++;
                continue;
            }
            $totalRaw++;

            $skuUpper = strtoupper($skuRaw);
            if (isset($seenUpper[$skuUpper])) {
                $dedupSkipped++;
                continue;
            }
            $seenUpper[$skuUpper] = $rowExcel;

            $out[] = [
                'row_excel' => $rowExcel,
                'sku' => $skuRaw,
                'name_ref' => $nameRaw === '' ? null : $nameRaw,
            ];
        }

        return [
            'rows' => $out,
            'total_raw' => $totalRaw,
            'dedup_skipped' => $dedupSkipped,
            'empty_skipped' => $emptySkipped,
            'truncated' => $truncated,
        ];
    }

    private function looksLikeHeader(mixed $firstCell): bool
    {
        if ($firstCell === null) {
            return false;
        }
        $v = strtolower(trim((string) $firstCell));

        return in_array($v, ['sku', 'kode', 'kode produk', 'kode_produk', 'product', 'produk'], true);
    }
}
