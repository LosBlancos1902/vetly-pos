<?php

namespace App\Services\Master;

use App\Models\Tenant\Category;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\PriceTier;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductUnit;
use App\Models\Tenant\ProductUnitPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Validasi + apply hasil parser ke DB.
 *
 * Mode operasi:
 *   - PREVIEW (dryRun=true): jalankan SEMUA validasi + bangun action plan,
 *     TIDAK pernah call ke DB write method. Pakai SELECT-only.
 *   - COMMIT (dryRun=false): re-validasi + apply dalam DB transaction.
 *     Baris valid → insert/update. Baris invalid → skip + report.
 *
 * Guardrails:
 *   - Stok TIDAK PERNAH disentuh (no Inventory::update, no StockMovement)
 *   - Rasio satuan existing yg berbeda → SKIP perubahan rasio + warning
 *     (harga di satuan itu tetap di-update)
 *   - Kategori WAJIB sudah exist (sesuai keputusan owner) — error kalau baru
 *   - Master unit WAJIB sudah exist — error kalau baru (jangan polusi baseline)
 *   - Partial: baris error di-skip, baris valid tetap jalan
 */
class ProductImportProcessor
{
    private const VALID_JENIS = [
        Product::TYPE_SALEABLE_RETAIL,
        Product::TYPE_COMPOUNDABLE_DRUG,
        Product::TYPE_SERVICE,
        Product::TYPE_SERVICE_WITH_CONSUMPTION,
        Product::TYPE_RAW_MATERIAL,
    ];

    /**
     * Run import. $parserOutput dari ProductExcelParser::parse().
     *
     * @return array{
     *     summary: array{insert: int, update: int, skip: int, warnings: int},
     *     rows: list<array<string, mixed>>,
     *     fatal_errors: list<string>
     * }
     */
    public function run(array $parserOutput, bool $dryRun): array
    {
        // Fatal: header missing → tidak bisa jalan
        if ($parserOutput['missing_headers'] !== []) {
            return [
                'summary' => ['insert' => 0, 'update' => 0, 'skip' => 0, 'warnings' => 0],
                'rows' => [],
                'fatal_errors' => array_map(
                    fn ($h) => "Kolom wajib tidak ada di header: '{$h}'",
                    $parserOutput['missing_headers'],
                ),
            ];
        }

        $rows = $parserOutput['rows'];
        $tierSlugToId = $parserOutput['tier_slug_to_id'];
        $defaultTierId = (int) PriceTier::where('is_default', true)->value('id');
        $defaultTierSlug = array_search($defaultTierId, $tierSlugToId, true) ?: null;

        // Pre-load lookup data (avoid N+1 di validation loop)
        $unitCodeToId = MasterUnit::pluck('id', 'code')->all();
        $categoryNameToId = [];
        foreach (Category::all(['id', 'name']) as $c) {
            $categoryNameToId[strtolower($c->name)] = $c->id;
        }
        $existingSkus = Product::pluck('id', 'sku')->all();

        // Validation pass 1: per-row + cross-row duplicate detection
        $skuSeen = [];
        $reports = [];
        foreach ($rows as $row) {
            $report = $this->validateRow(
                $row,
                $defaultTierSlug,
                $unitCodeToId,
                $categoryNameToId,
                $existingSkus,
                $skuSeen,
            );
            $reports[] = $report;
            $skuSeen[$row['kode_barang']] = $report['row_num'];
        }

        $summary = ['insert' => 0, 'update' => 0, 'skip' => 0, 'warnings' => 0];
        foreach ($reports as $r) {
            $summary[$r['action']]++;
            if ($r['warnings'] !== []) {
                $summary['warnings']++;
            }
        }

        if ($dryRun) {
            return [
                'summary' => $summary,
                'rows' => $reports,
                'fatal_errors' => [],
            ];
        }

        // COMMIT: apply dalam single transaction
        DB::transaction(function () use ($reports, $rows, $unitCodeToId, $categoryNameToId) {
            foreach ($reports as $i => $report) {
                if ($report['action'] === 'skip') {
                    continue;
                }
                $row = $rows[$i];

                if ($report['action'] === 'insert') {
                    $this->insertProduct($row, $report, $unitCodeToId, $categoryNameToId);
                } elseif ($report['action'] === 'update') {
                    $this->updateProduct($row, $report, $unitCodeToId, $categoryNameToId);
                }
            }
        });

        return [
            'summary' => $summary,
            'rows' => $reports,
            'fatal_errors' => [],
        ];
    }

    /**
     * Validate 1 row → return report (action: insert|update|skip).
     */
    private function validateRow(
        array $row,
        ?string $defaultTierSlug,
        array $unitCodeToId,
        array $categoryNameToId,
        array $existingSkus,
        array $skuSeen,
    ): array {
        $errors = [];
        $warnings = [];

        // Basic required
        if ($row['nama'] === '') {
            $errors[] = 'nama kosong';
        }
        if ($row['kode_barang'] === '') {
            $errors[] = 'kode_barang kosong';
        }
        if ($row['kategori'] === '') {
            $errors[] = 'kategori kosong';
        }
        if ($row['jenis'] === '') {
            $errors[] = 'jenis kosong';
        } elseif (! in_array($row['jenis'], self::VALID_JENIS, true)) {
            $errors[] = "jenis '{$row['jenis']}' tidak valid (harus: ".implode(', ', self::VALID_JENIS).')';
        }

        // Kategori WAJIB exist (per keputusan owner)
        if ($row['kategori'] !== '' && ! isset($categoryNameToId[strtolower($row['kategori'])])) {
            $errors[] = "kategori '{$row['kategori']}' tidak terdaftar — buat dulu via Master Kategori atau tinker";
        }

        // Duplikat dalam file
        if ($row['kode_barang'] !== '' && isset($skuSeen[$row['kode_barang']])) {
            $errors[] = "kode_barang duplikat di file (sudah ada di baris {$skuSeen[$row['kode_barang']]})";
        }

        // Validasi units
        if ($row['units'] === []) {
            $errors[] = 'satuan_base tidak diisi';
        } else {
            // Base unit harus ada (slot=base)
            $hasBase = false;
            $unitCodesInRow = [];
            foreach ($row['units'] as $u) {
                if ($u['slot'] === 'base') {
                    $hasBase = true;
                }
                // Unit code harus terdaftar di master_units
                if (! isset($unitCodeToId[$u['code']])) {
                    $errors[] = "satuan '{$u['code']}' tidak terdaftar di master_units";
                }
                // Cek duplikat code dalam 1 produk
                if (in_array($u['code'], $unitCodesInRow, true)) {
                    $errors[] = "satuan '{$u['code']}' duplikat dalam 1 produk";
                }
                $unitCodesInRow[] = $u['code'];
                // Rasio: untuk turunan, harus > 0 dan ≥ 0.01 (reuse fix desimal threshold)
                if ($u['slot'] !== 'base') {
                    if ($u['rasio'] === null) {
                        $errors[] = "rasio untuk satuan '{$u['code']}' kosong/invalid";
                    } elseif ($u['rasio'] < 0.01) {
                        $errors[] = "rasio satuan '{$u['code']}' = {$u['rasio']} terlalu kecil (min 0.01)";
                    }
                }
            }
            if (! $hasBase) {
                $errors[] = 'satuan_base tidak ada';
            }
        }

        // Validasi prices: harga default tier × base WAJIB
        $hasDefaultBase = false;
        foreach ($row['prices'] as $p) {
            if ($p['tier_slug'] === $defaultTierSlug && $p['unit_slot'] === 'base') {
                $hasDefaultBase = true;
            }
            if ($p['price'] < 0) {
                $errors[] = "harga negatif di tier {$p['tier_slug']} satuan {$p['unit_slot']}";
            }
        }
        if (! $hasDefaultBase) {
            $errors[] = "harga tier default (slug={$defaultTierSlug}) untuk satuan_base wajib diisi";
        }

        if ($errors !== []) {
            return [
                'row_num' => $row['row_num'],
                'sku' => $row['kode_barang'],
                'name' => $row['nama'],
                'action' => 'skip',
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        // Tentukan insert vs update
        $existingProductId = $existingSkus[$row['kode_barang']] ?? null;
        $action = $existingProductId ? 'update' : 'insert';

        // Untuk update: cek satuan existing rasio bentrok → warning (bukan error)
        if ($action === 'update' && $existingProductId) {
            $existingUnits = ProductUnit::where('product_id', $existingProductId)
                ->with('unit:id,code')
                ->get(['id', 'unit_id', 'level', 'conversion_to_base']);
            $existingByCode = [];
            foreach ($existingUnits as $eu) {
                $existingByCode[$eu->unit?->code] = (float) $eu->conversion_to_base;
            }
            foreach ($row['units'] as $u) {
                if (isset($existingByCode[$u['code']])) {
                    $dbRasio = $existingByCode[$u['code']];
                    if (abs($dbRasio - $u['rasio']) > 0.0001) {
                        $warnings[] = "rasio satuan '{$u['code']}' di Excel ({$u['rasio']}) beda dari DB ({$dbRasio}) — dipertahankan {$dbRasio}, harga tetap di-update";
                    }
                }
            }
            // Satuan existing yg tidak ada di Excel
            foreach ($existingByCode as $code => $_) {
                $stillInExcel = collect($row['units'])->firstWhere('code', $code);
                if (! $stillInExcel) {
                    $warnings[] = "satuan '{$code}' ada di DB tapi tidak di Excel — dipertahankan";
                }
            }
        }

        return [
            'row_num' => $row['row_num'],
            'sku' => $row['kode_barang'],
            'name' => $row['nama'],
            'action' => $action,
            'existing_product_id' => $existingProductId,
            'errors' => [],
            'warnings' => $warnings,
        ];
    }

    private function insertProduct(array $row, array $report, array $unitCodeToId, array $categoryNameToId): void
    {
        $catId = $categoryNameToId[strtolower($row['kategori'])];
        $baseUnit = collect($row['units'])->firstWhere('slot', 'base');
        $baseUnitId = $unitCodeToId[$baseUnit['code']];

        $product = Product::create([
            'sku' => $row['kode_barang'],
            'name' => $row['nama'],
            'barcode' => $row['barcode'],
            'category_id' => $catId,
            'base_unit_id' => $baseUnitId,
            'type' => $row['jenis'],
            'price' => $this->extractLegacyBasePrice($row),
            'cost_avg' => 0,
            'is_active' => true,
        ]);

        $this->upsertUnitsAndPrices($product, $row, $unitCodeToId, isInsert: true);
    }

    private function updateProduct(array $row, array $report, array $unitCodeToId, array $categoryNameToId): void
    {
        $product = Product::findOrFail($report['existing_product_id']);
        $catId = $categoryNameToId[strtolower($row['kategori'])];
        $baseUnit = collect($row['units'])->firstWhere('slot', 'base');
        $baseUnitId = $unitCodeToId[$baseUnit['code']];

        $product->update([
            'name' => $row['nama'],
            'barcode' => $row['barcode'] ?? $product->barcode,
            'category_id' => $catId,
            'base_unit_id' => $baseUnitId,
            'type' => $row['jenis'],
            'price' => $this->extractLegacyBasePrice($row),
        ]);

        $this->upsertUnitsAndPrices($product, $row, $unitCodeToId, isInsert: false);
    }

    /**
     * Untuk INSERT: buat semua units + prices fresh.
     * Untuk UPDATE:
     *   - Satuan existing dgn code sama: KEEP (rasio tidak diubah meski Excel beda — sudah di-warning)
     *   - Satuan baru: INSERT level berikutnya
     *   - Satuan DB yg tidak ada di Excel: KEEP (tidak di-delete)
     *   - Harga: REPLACE per (unit, tier) cell yg di-Excel; cell kosong = preserve existing
     */
    private function upsertUnitsAndPrices(Product $product, array $row, array $unitCodeToId, bool $isInsert): void
    {
        if ($isInsert) {
            // Fresh: create semua dari Excel
            $unitIdByCode = [];
            $level = 1;
            foreach ($row['units'] as $u) {
                $unitId = $unitCodeToId[$u['code']];
                $productUnit = ProductUnit::create([
                    'product_id' => $product->id,
                    'unit_id' => $unitId,
                    'level' => $level++,
                    'conversion_to_base' => $u['rasio'],
                    'is_purchase_unit' => true,
                    'is_sale_unit' => true,
                ]);
                $unitIdByCode[$u['code']] = $productUnit->id;
            }

            foreach ($row['prices'] as $p) {
                $unitCode = $this->slotToCode($row['units'], $p['unit_slot']);
                if ($unitCode === null || ! isset($unitIdByCode[$unitCode])) {
                    continue;
                }
                ProductUnitPrice::create([
                    'product_unit_id' => $unitIdByCode[$unitCode],
                    'price_tier_id' => $p['tier_id'],
                    'price' => $p['price'],
                ]);
            }

            return;
        }

        // UPDATE path
        $existingUnits = ProductUnit::where('product_id', $product->id)
            ->with('unit:id,code')
            ->get();
        $existingByCode = $existingUnits->keyBy(fn ($pu) => $pu->unit?->code);
        $maxLevel = $existingUnits->max('level') ?? 0;

        $unitIdByCode = []; // code → product_unit.id (existing + newly added)
        foreach ($existingByCode as $code => $pu) {
            $unitIdByCode[$code] = $pu->id;
        }

        foreach ($row['units'] as $u) {
            if (isset($existingByCode[$u['code']])) {
                // Existing: JANGAN ubah conversion_to_base (sudah di-warn). No-op satuan side.
                continue;
            }
            // Satuan baru → add level berikutnya
            $unitId = $unitCodeToId[$u['code']];
            $maxLevel++;
            $newUnit = ProductUnit::create([
                'product_id' => $product->id,
                'unit_id' => $unitId,
                'level' => $maxLevel,
                'conversion_to_base' => $u['rasio'],
                'is_purchase_unit' => true,
                'is_sale_unit' => true,
            ]);
            $unitIdByCode[$u['code']] = $newUnit->id;
        }

        // Harga: upsert per cell yg ada di Excel
        foreach ($row['prices'] as $p) {
            $unitCode = $this->slotToCode($row['units'], $p['unit_slot']);
            if ($unitCode === null || ! isset($unitIdByCode[$unitCode])) {
                continue;
            }
            ProductUnitPrice::updateOrCreate(
                [
                    'product_unit_id' => $unitIdByCode[$unitCode],
                    'price_tier_id' => $p['tier_id'],
                ],
                ['price' => $p['price']],
            );
        }
    }

    private function slotToCode(array $units, string $slot): ?string
    {
        foreach ($units as $u) {
            if ($u['slot'] === $slot) {
                return $u['code'];
            }
        }

        return null;
    }

    private function extractLegacyBasePrice(array $row): float
    {
        $defaultTierId = (int) PriceTier::where('is_default', true)->value('id');
        foreach ($row['prices'] as $p) {
            if ($p['tier_id'] === $defaultTierId && $p['unit_slot'] === 'base') {
                return $p['price'];
            }
        }

        return 0;
    }
}
