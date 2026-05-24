<?php

namespace App\Services\Master;

use App\Models\Tenant\PriceTier;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Parse Excel file → normalized rows. Tidak ada DB write; cuma read +
 * shape. Header dipetakan by NAME (bukan posisi index) supaya tahan
 * shuffled columns.
 *
 * Output per row (normalized):
 *   [
 *     'row_num' => int,           // nomor baris di Excel (header = 1, data mulai 2)
 *     'nama' => string,
 *     'kategori' => string,
 *     'jenis' => string,
 *     'kode_barang' => string,
 *     'barcode' => string|null,
 *     'units' => [
 *       ['slot' => 'base'|'satuan_2'|..., 'code' => 'pcs', 'rasio' => 1.0],
 *       ...
 *     ],
 *     'prices' => [
 *       ['tier_slug' => 'eceran', 'unit_slot' => 'base', 'price' => 25000.0],
 *       ...
 *     ],
 *   ]
 */
class ProductExcelParser
{
    private const UNIT_SLOTS = ['base', 'satuan_2', 'satuan_3', 'satuan_4', 'satuan_5'];

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     tier_slug_to_id: array<string, int>,
     *     missing_headers: list<string>
     * }
     */
    public function parse(string $filePath): array
    {
        $tiers = PriceTier::orderBy('sort_order')->get(['id', 'name', 'is_default']);
        $tierSlugToId = [];
        foreach ($tiers as $t) {
            $tierSlugToId[Str::slug($t->name, '_')] = (int) $t->id;
        }
        $defaultTier = $tiers->firstWhere('is_default', true);
        $defaultSlug = $defaultTier ? Str::slug($defaultTier->name, '_') : 'default';

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName('Produk') ?? $spreadsheet->getActiveSheet();
        $raw = $sheet->toArray(null, true, true, false);

        if (count($raw) < 1) {
            return ['rows' => [], 'tier_slug_to_id' => $tierSlugToId, 'missing_headers' => ['Sheet kosong']];
        }

        // Map header name → column index
        $headerRow = array_shift($raw);
        $headerMap = [];
        foreach ($headerRow as $i => $h) {
            $norm = strtolower(trim((string) $h));
            if ($norm !== '') {
                $headerMap[$norm] = $i;
            }
        }

        // Required headers
        $requiredHeaders = [
            'nama', 'kategori', 'jenis', 'kode_barang', 'satuan_base',
            "harga_{$defaultSlug}_base",
        ];
        $missing = [];
        foreach ($requiredHeaders as $req) {
            if (! array_key_exists($req, $headerMap)) {
                $missing[] = $req;
            }
        }
        if ($missing !== []) {
            return ['rows' => [], 'tier_slug_to_id' => $tierSlugToId, 'missing_headers' => $missing];
        }

        $rows = [];
        foreach ($raw as $idx => $r) {
            $rowNum = $idx + 2; // data mulai row 2 (Excel-style)

            $get = fn (string $h) => array_key_exists($h, $headerMap)
                ? ($r[$headerMap[$h]] ?? null)
                : null;

            // Skip baris kosong sepenuhnya
            $nama = trim((string) ($get('nama') ?? ''));
            $sku = trim((string) ($get('kode_barang') ?? ''));
            if ($nama === '' && $sku === '') {
                continue;
            }

            // Build units list (slot → code+rasio)
            $units = [];
            foreach (self::UNIT_SLOTS as $slot) {
                if ($slot === 'base') {
                    $code = trim((string) ($get('satuan_base') ?? ''));
                    if ($code !== '') {
                        $units[] = ['slot' => 'base', 'code' => $code, 'rasio' => 1.0];
                    }
                } else {
                    $code = trim((string) ($get($slot) ?? ''));
                    $rasioRaw = $get('rasio_'.substr($slot, -1));
                    if ($code !== '') {
                        $units[] = [
                            'slot' => $slot,
                            'code' => $code,
                            'rasio' => is_numeric($rasioRaw) ? (float) $rasioRaw : null,
                        ];
                    }
                }
            }

            // Build prices list (tier × slot)
            $prices = [];
            foreach ($tierSlugToId as $tierSlug => $tierId) {
                foreach (self::UNIT_SLOTS as $slot) {
                    $headerKey = "harga_{$tierSlug}_{$slot}";
                    $val = $get($headerKey);
                    if ($val !== null && $val !== '' && is_numeric($val)) {
                        $prices[] = [
                            'tier_slug' => $tierSlug,
                            'tier_id' => $tierId,
                            'unit_slot' => $slot,
                            'price' => (float) $val,
                        ];
                    }
                }
            }

            $rows[] = [
                'row_num' => $rowNum,
                'nama' => $nama,
                'kategori' => trim((string) ($get('kategori') ?? '')),
                'jenis' => trim((string) ($get('jenis') ?? '')),
                'kode_barang' => $sku,
                'barcode' => trim((string) ($get('barcode') ?? '')) ?: null,
                'units' => $units,
                'prices' => $prices,
            ];
        }

        return [
            'rows' => $rows,
            'tier_slug_to_id' => $tierSlugToId,
            'missing_headers' => [],
        ];
    }
}
