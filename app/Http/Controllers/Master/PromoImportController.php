<?php

declare(strict_types=1);

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use App\Services\Master\PromoExcelParser;
use App\Services\Master\PromoExcelTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk pilih daftar produk untuk Promo Per-Barang via Excel.
 *
 * Flow:
 *   1. downloadTemplate() — xlsx template (sheet Produk + Instruksi)
 *   2. preview()          — upload + parse + match SKU → JSON
 *      { matched: [...], unmatched: [...], summary: {...} }
 *
 * TIDAK ada commit endpoint — UI yang gabungkan hasil preview ke form
 * promo, lalu form di-submit lewat PromoController::update/store yang
 * sudah ada (validasi promo full di sana).
 *
 * Permission: promo.manage (sama dengan PromoController).
 */
class PromoImportController extends Controller
{
    public function downloadTemplate(PromoExcelTemplate $template): BinaryFileResponse
    {
        $this->authorize('promo.manage');

        $tmpPath = $template->generate();
        $filename = 'promo-items-template-'.now()->format('Ymd-Hi').'.xlsx';

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function preview(Request $request, PromoExcelParser $parser): JsonResponse
    {
        $this->authorize('promo.manage');

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        $parsed = $parser->parse($request->file('file')->getRealPath());

        if ($parsed['rows'] === []) {
            return response()->json([
                'matched' => [],
                'unmatched' => [],
                'summary' => [
                    'total_input' => 0,
                    'matched_count' => 0,
                    'unmatched_count' => 0,
                    'dedup_skipped' => $parsed['dedup_skipped'],
                    'empty_skipped' => $parsed['empty_skipped'],
                    'truncated' => $parsed['truncated'],
                ],
            ]);
        }

        // Lookup map SKU UPPERCASE → product. Hanya produk AKTIF + sellable
        // (konsisten dengan picker UI yang ada di PromoController::index).
        $skusUpper = array_map(fn ($r) => strtoupper($r['sku']), $parsed['rows']);
        $products = Product::query()
            ->where('is_active', true)
            ->where('is_sellable_directly', true)
            ->whereIn(DB::raw('UPPER(sku)'), $skusUpper)
            ->get(['id', 'sku', 'name', 'category_id'])
            ->keyBy(fn ($p) => strtoupper($p->sku));

        $matched = [];
        $unmatched = [];
        $matchedIdSeen = []; // anti-dup kalau ada SKU dgn casing beda yg ter-map ke product sama
        foreach ($parsed['rows'] as $r) {
            $key = strtoupper($r['sku']);
            $p = $products->get($key);
            if ($p && ! isset($matchedIdSeen[$p->id])) {
                $matched[] = [
                    'id' => (int) $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'category_id' => $p->category_id,
                    'row_excel' => $r['row_excel'],
                ];
                $matchedIdSeen[$p->id] = true;
            } else {
                $unmatched[] = [
                    'row_excel' => $r['row_excel'],
                    'sku' => $r['sku'],
                    'name_ref' => $r['name_ref'],
                ];
            }
        }

        return response()->json([
            'matched' => $matched,
            'unmatched' => $unmatched,
            'summary' => [
                'total_input' => $parsed['total_raw'],
                'matched_count' => count($matched),
                'unmatched_count' => count($unmatched),
                'dedup_skipped' => $parsed['dedup_skipped'],
                'empty_skipped' => $parsed['empty_skipped'],
                'truncated' => $parsed['truncated'],
            ],
        ]);
    }
}
