<?php

declare(strict_types=1);

namespace App\Services\Master;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Template Excel untuk daftar produk yang masuk Promo Per-Barang.
 *
 * Format minimal — 2 kolom:
 *   - sku (required)           — kunci match ke products.sku
 *   - nama (optional)          — referensi visual saja, server abaikan
 *
 * Sheet "Produk": header + 3 contoh baris.
 * Sheet "Instruksi": petunjuk singkat.
 */
class PromoExcelTemplate
{
    public function generate(): string
    {
        $spreadsheet = new Spreadsheet();

        // ─── Sheet 1: Produk ──────────────────────────────────────────────
        $produkSheet = $spreadsheet->getActiveSheet();
        $produkSheet->setTitle('Produk');

        $headers = ['sku', 'nama (opsional - referensi saja)'];
        foreach ($headers as $i => $h) {
            $cell = chr(65 + $i).'1';
            $produkSheet->setCellValue($cell, $h);
            $produkSheet->getStyle($cell)->getFont()->setBold(true);
            $produkSheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFE0E7FF');
        }

        $examples = [
            ['SKU-001', 'Contoh — Royal Canin 1kg'],
            ['SKU-002', 'Contoh — Whiskas Sachet'],
            ['SKU-003', ''],
        ];
        foreach ($examples as $idx => $row) {
            $rowNum = $idx + 2;
            $produkSheet->setCellValue("A{$rowNum}", $row[0]);
            $produkSheet->setCellValue("B{$rowNum}", $row[1]);
        }

        $produkSheet->getColumnDimension('A')->setWidth(20);
        $produkSheet->getColumnDimension('B')->setAutoSize(true);

        // ─── Sheet 2: Instruksi ──────────────────────────────────────────
        $instr = $spreadsheet->createSheet();
        $instr->setTitle('Instruksi');

        $lines = [
            ['CARA PAKAI', ''],
            ['', ''],
            ['1.', 'Isi kolom "sku" dengan kode produk yang ingin dimasukkan ke promo.'],
            ['2.', 'Kolom "nama" hanya referensi visual — sistem MENGABAIKAN nama, match cuma pakai SKU.'],
            ['3.', 'Satu baris = satu produk. Baris kosong di-skip.'],
            ['4.', 'SKU duplikat (mis. "SKU-001" muncul 2 kali) di-dedup otomatis (case-insensitive).'],
            ['5.', 'Setelah upload, sistem tampilkan PREVIEW:'],
            ['', '   • produk yang SKU-nya ketemu → siap ditambahkan ke promo'],
            ['', '   • produk yang SKU-nya TIDAK ketemu → ditampilkan dengan nomor baris-nya'],
            ['6.', 'Klik "Tambahkan ke Promo" untuk merge produk yang match ke daftar item promo.'],
            ['7.', 'Produk lama di promo TIDAK dihapus — upload Excel hanya MENAMBAH.'],
            ['', ''],
            ['CATATAN', ''],
            ['•', 'Hanya produk AKTIF + sellable yang bisa di-match.'],
            ['•', 'Format file: .xlsx atau .xls, maksimum 5 MB.'],
            ['•', 'Cara ini PELENGKAP pilih manual — tidak menggantikan. Bisa kombinasi.'],
        ];
        foreach ($lines as $idx => [$col1, $col2]) {
            $rowNum = $idx + 1;
            $instr->setCellValue("A{$rowNum}", $col1);
            $instr->setCellValue("B{$rowNum}", $col2);
        }
        $instr->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $instr->getStyle('A13')->getFont()->setBold(true)->setSize(12);
        $instr->getColumnDimension('A')->setWidth(8);
        $instr->getColumnDimension('B')->setWidth(80);

        $spreadsheet->setActiveSheetIndex(0);

        $tmpPath = tempnam(sys_get_temp_dir(), 'promo-tpl-').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return $tmpPath;
    }
}
