<?php

namespace App\Services\Master;

use App\Models\Tenant\PriceTier;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Generate Excel template untuk import produk massal.
 *
 * Format flat (1 row = 1 produk). Header dinamis: kolom harga di-generate
 * dari tier existing tenant. Slot satuan hardcoded ke 5 (base + 4
 * turunan) — cover skenario pharmacy/retail kompleks.
 *
 * Output 2 sheet:
 *   - "Produk": header + 2 baris contoh (1 simple, 1 multi-satuan multi-tier)
 *   - "Instruksi": daftar tier existing, valid `jenis`, aturan kolom
 */
class ProductExcelExporter
{
    public const MAX_UNIT_SLOTS = 5; // base + 4 turunan

    /** @var list<string> Slot label untuk header harga */
    private const UNIT_SLOTS = ['base', 'satuan_2', 'satuan_3', 'satuan_4', 'satuan_5'];

    public function generate(): string
    {
        $tiers = PriceTier::orderBy('sort_order')->get(['id', 'name', 'is_default']);
        $defaultTier = $tiers->firstWhere('is_default', true);

        $spreadsheet = new Spreadsheet();

        // ─── Sheet 1: Produk ──────────────────────────────────────────────
        $produkSheet = $spreadsheet->getActiveSheet();
        $produkSheet->setTitle('Produk');

        $headers = $this->buildHeaders($tiers);
        foreach ($headers as $i => $h) {
            $cell = $this->colLetter($i + 1).'1';
            $produkSheet->setCellValue($cell, $h);
            $produkSheet->getStyle($cell)->getFont()->setBold(true);
            $produkSheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFE0E7FF');
        }

        // Contoh baris 1: simple retail, cuma base + 1 tier default
        $defaultSlug = $defaultTier ? Str::slug($defaultTier->name, '_') : 'default';
        $exampleHarga1 = [];
        foreach ($tiers as $t) {
            $slug = Str::slug($t->name, '_');
            foreach (self::UNIT_SLOTS as $slot) {
                $exampleHarga1["harga_{$slug}_{$slot}"] = $slug === $defaultSlug && $slot === 'base' ? 25000 : '';
            }
        }
        $row1 = array_merge([
            'nama' => 'Contoh Royal Canin 1kg',
            'kategori' => 'Pakan Kucing',
            'jenis' => 'saleable_retail',
            'kode_barang' => 'DEMO-RC-001',
            'barcode' => '8991234567890',
            'satuan_base' => 'pcs',
            'satuan_2' => '',
            'rasio_2' => '',
            'satuan_3' => '',
            'rasio_3' => '',
            'satuan_4' => '',
            'rasio_4' => '',
            'satuan_5' => '',
            'rasio_5' => '',
        ], $exampleHarga1);
        $this->writeRow($produkSheet, 2, $headers, $row1);

        // Contoh baris 2: multi-satuan (pcs base, dus = 12 pcs) + multi-tier
        $exampleHarga2 = [];
        foreach ($tiers as $t) {
            $slug = Str::slug($t->name, '_');
            foreach (self::UNIT_SLOTS as $slot) {
                if ($slot === 'base') {
                    $exampleHarga2["harga_{$slug}_{$slot}"] = $slug === $defaultSlug ? 10000 : 9500;
                } elseif ($slot === 'satuan_2') {
                    $exampleHarga2["harga_{$slug}_{$slot}"] = $slug === $defaultSlug ? 115000 : '';
                } else {
                    $exampleHarga2["harga_{$slug}_{$slot}"] = '';
                }
            }
        }
        $row2 = array_merge([
            'nama' => 'Contoh Whiskas Sachet',
            'kategori' => 'Pakan Kucing',
            'jenis' => 'saleable_retail',
            'kode_barang' => 'DEMO-WS-002',
            'barcode' => '',
            'satuan_base' => 'pcs',
            'satuan_2' => 'dus',
            'rasio_2' => 12,
            'satuan_3' => '',
            'rasio_3' => '',
            'satuan_4' => '',
            'rasio_4' => '',
            'satuan_5' => '',
            'rasio_5' => '',
        ], $exampleHarga2);
        $this->writeRow($produkSheet, 3, $headers, $row2);

        // Auto-size kolom
        foreach (range(1, count($headers)) as $i) {
            $produkSheet->getColumnDimension($this->colLetter($i))->setAutoSize(true);
        }

        // ─── Sheet 2: Instruksi ───────────────────────────────────────────
        $instrSheet = $spreadsheet->createSheet();
        $instrSheet->setTitle('Instruksi');

        $instrSheet->setCellValue('A1', 'PETUNJUK IMPORT PRODUK MASSAL');
        $instrSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $instrSheet->mergeCells('A1:C1');

        $instr = [
            ['', '', ''],
            ['1. Kolom WAJIB', '', ''],
            ['', 'nama', 'Nama produk'],
            ['', 'kategori', 'HARUS sudah terdaftar — lihat daftar di bawah'],
            ['', 'jenis', 'Salah satu dari: saleable_retail, raw_material, compoundable_drug, service, service_with_consumption'],
            ['', 'kode_barang', 'SKU unik per tenant. Match by kolom ini untuk update.'],
            ['', 'satuan_base', 'Kode satuan base (mis. pcs/ml/g). Lihat daftar di bawah.'],
            ['', "harga_{$defaultSlug}_base", "Wajib diisi (tier default = \"".($defaultTier?->name ?? 'Eceran').'")'],
            ['', '', ''],
            ['2. Kolom OPSIONAL', '', ''],
            ['', 'barcode', 'Barcode produk (boleh kosong)'],
            ['', 'satuan_2..satuan_5', 'Satuan turunan. Kalau diisi, kolom rasio_X wajib.'],
            ['', 'rasio_2..rasio_5', 'Konversi ke base. Mis. 1 dus = 12 pcs → rasio_2=12. Minimal 0.01.'],
            ['', 'harga_<tier>_<slot>', 'Cell kosong = fallback ke harga tier default untuk satuan yg sama.'],
            ['', '', ''],
            ['3. MODE INSERT vs UPDATE', '', ''],
            ['', 'INSERT', 'Kode_barang belum ada → produk baru dibuat'],
            ['', 'UPDATE', 'Kode_barang sudah ada → master + harga di-update'],
            ['', '⚠️ STOK', 'Import TIDAK PERNAH menyentuh stok. Pakai Stock Adjustment / Opname.'],
            ['', '⚠️ Rasio satuan', 'Kalau satuan existing rasio-nya berubah di Excel → dipertahankan rasio lama + WARNING. Harga tetap di-update.'],
            ['', '', ''],
            ['4. ALUR IMPORT', '', ''],
            ['', '1. Upload', 'Sistem parse + validasi, TIDAK menulis ke DB'],
            ['', '2. Preview', 'Lihat ringkasan: insert/update/skip + error per baris'],
            ['', '3. Konfirmasi', 'Klik tombol "Konfirmasi & Import" → DB write dgn transaction'],
            ['', '', ''],
        ];

        $row = 2;
        foreach ($instr as $r) {
            $instrSheet->setCellValue('A'.$row, $r[0]);
            $instrSheet->setCellValue('B'.$row, $r[1]);
            $instrSheet->setCellValue('C'.$row, $r[2]);
            if ($r[0] !== '' && ! str_starts_with($r[0], ' ')) {
                $instrSheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
            }
            $row++;
        }

        // Tier existing
        $instrSheet->setCellValue('A'.$row, '5. TIER EXISTING di tenant:');
        $instrSheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        foreach ($tiers as $t) {
            $slug = Str::slug($t->name, '_');
            $instrSheet->setCellValue('B'.$row, $t->name);
            $instrSheet->setCellValue('C'.$row, 'slug: '.$slug.($t->is_default ? ' (DEFAULT)' : ''));
            $row++;
        }

        // Satuan existing
        $row++;
        $instrSheet->setCellValue('A'.$row, '6. SATUAN (master_units) yg terdaftar:');
        $instrSheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        foreach (\App\Models\Tenant\MasterUnit::orderBy('code')->get(['code', 'name']) as $u) {
            $instrSheet->setCellValue('B'.$row, $u->code);
            $instrSheet->setCellValue('C'.$row, $u->name);
            $row++;
        }

        // Kategori existing
        $row++;
        $instrSheet->setCellValue('A'.$row, '7. KATEGORI yg terdaftar (WAJIB pakai salah satu):');
        $instrSheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
        foreach (\App\Models\Tenant\Category::orderBy('name')->get(['name']) as $c) {
            $instrSheet->setCellValue('B'.$row, $c->name);
            $row++;
        }

        $instrSheet->getColumnDimension('A')->setWidth(5);
        $instrSheet->getColumnDimension('B')->setWidth(30);
        $instrSheet->getColumnDimension('C')->setWidth(60);
        $instrSheet->getStyle('B:C')->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        // ─── Save to temp ─────────────────────────────────────────────────
        $tmpPath = tempnam(sys_get_temp_dir(), 'product-template-').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return $tmpPath;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PriceTier>  $tiers
     * @return list<string>
     */
    private function buildHeaders($tiers): array
    {
        $fixed = [
            'nama', 'kategori', 'jenis', 'kode_barang', 'barcode',
            'satuan_base',
            'satuan_2', 'rasio_2',
            'satuan_3', 'rasio_3',
            'satuan_4', 'rasio_4',
            'satuan_5', 'rasio_5',
        ];

        $hargaHeaders = [];
        foreach ($tiers as $t) {
            $slug = Str::slug($t->name, '_');
            foreach (self::UNIT_SLOTS as $slot) {
                $hargaHeaders[] = "harga_{$slug}_{$slot}";
            }
        }

        return array_merge($fixed, $hargaHeaders);
    }

    private function writeRow($sheet, int $rowNum, array $headers, array $values): void
    {
        foreach ($headers as $i => $h) {
            $cell = $this->colLetter($i + 1).$rowNum;
            $val = $values[$h] ?? '';
            if ($val !== '') {
                $sheet->setCellValue($cell, $val);
            }
        }
    }

    /**
     * Convert 1-based column index to Excel letter (1=A, 27=AA, ...).
     */
    private function colLetter(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $r = ($n - 1) % 26;
            $s = chr(65 + $r).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }
}
