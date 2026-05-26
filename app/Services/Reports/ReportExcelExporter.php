<?php

declare(strict_types=1);

namespace App\Services\Reports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reusable Excel exporter untuk laporan. Format FLAT-TABULAR:
 *   - Header di baris 1 (bold + tinted background)
 *   - Data mulai baris 2
 *   - TIDAK ada merge cell, sub-heading di tengah, footer fancy
 *   - 1 baris = 1 record → user gampang pivot/filter/edit
 *
 * Multi-sheet didukung. Setiap sheet adalah dataset independen.
 */
class ReportExcelExporter
{
    /** @var array<int, array{title:string, headers:list<string>, rows:list<array>}> */
    private array $sheets = [];

    public function addSheet(string $title, array $headers, array $rows): self
    {
        // PhpSpreadsheet max title 31 chars + tidak boleh chars: \ / ? * [ ]
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '_', $title) ?? $title;
        $this->sheets[] = [
            'title' => substr($clean, 0, 31),
            'headers' => $headers,
            'rows' => $rows,
        ];

        return $this;
    }

    public function download(string $filename): BinaryFileResponse
    {
        if ($this->sheets === []) {
            $this->addSheet('Kosong', ['Info'], [['(tidak ada data)']]);
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        foreach ($this->sheets as $idx => $s) {
            $sheet = $spreadsheet->createSheet($idx);
            $sheet->setTitle($s['title']);

            // Header row
            foreach ($s['headers'] as $i => $h) {
                $cell = $this->colLetter($i + 1).'1';
                $sheet->setCellValue($cell, $h);
                $sheet->getStyle($cell)->getFont()->setBold(true);
                $sheet->getStyle($cell)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFE0E7FF');
            }

            // Data rows — flat, 1 per line
            $rowNum = 2;
            foreach ($s['rows'] as $row) {
                $colIdx = 1;
                foreach ($s['headers'] as $headerKey => $_) {
                    // Support both ordered array (numeric index) and associative keyed by header label
                    $value = array_key_exists($headerKey, $row)
                        ? $row[$headerKey]
                        : ($row[$s['headers'][$headerKey]] ?? null);
                    $sheet->setCellValue($this->colLetter($colIdx).$rowNum, $value);
                    $colIdx++;
                }
                $rowNum++;
            }

            // Auto-size kolom (hanya kalau row < 5000 supaya nggak berat;
            // di atas itu fallback ke fixed width).
            $autoSize = count($s['rows']) <= 5000;
            for ($c = 1; $c <= count($s['headers']); $c++) {
                $letter = $this->colLetter($c);
                if ($autoSize) {
                    $sheet->getColumnDimension($letter)->setAutoSize(true);
                } else {
                    $sheet->getColumnDimension($letter)->setWidth(18);
                }
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $tmpPath = tempnam(sys_get_temp_dir(), 'report-').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function colLetter(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $rem = ($index - 1) % 26;
            $letters = chr(65 + $rem).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }
}
