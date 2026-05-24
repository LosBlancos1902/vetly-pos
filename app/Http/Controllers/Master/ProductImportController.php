<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Category;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\PriceTier;
use App\Services\Master\ProductExcelExporter;
use App\Services\Master\ProductExcelParser;
use App\Services\Master\ProductImportProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel bulk import untuk master produk. Flow:
 *   1. show()          → halaman /master/products/import
 *   2. downloadTemplate() → xlsx dgn header dinamis dari tier existing
 *   3. preview()       → parse + validate, return JSON summary, NO DB write
 *   4. commit()        → parse ulang + apply dlm transaction (partial OK)
 *
 * GUARDRAILS dipasang di ProductImportProcessor:
 *   - Stok tidak pernah disentuh
 *   - Rasio satuan existing dipertahankan (warning, bukan override)
 *   - Kategori wajib exist (sesuai keputusan owner)
 */
class ProductImportController extends Controller
{
    public function show(): Response
    {
        $this->authorize('master.manage');

        return Inertia::render('Master/ProductImport', [
            'tiers' => PriceTier::orderBy('sort_order')
                ->get(['id', 'name', 'sort_order', 'is_default', 'is_active']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'units' => MasterUnit::orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function downloadTemplate(ProductExcelExporter $exporter): BinaryFileResponse
    {
        $this->authorize('master.manage');

        $tmpPath = $exporter->generate();
        $filename = 'product-import-template-'.now()->format('Ymd-Hi').'.xlsx';

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function preview(
        Request $request,
        ProductExcelParser $parser,
        ProductImportProcessor $processor,
    ): JsonResponse {
        $this->authorize('master.manage');

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $parsed = $parser->parse($request->file('file')->getRealPath());
        $result = $processor->run($parsed, dryRun: true);

        return response()->json([
            'summary' => $result['summary'],
            'rows' => $result['rows'],
            'fatal_errors' => $result['fatal_errors'],
        ]);
    }

    public function commit(
        Request $request,
        ProductExcelParser $parser,
        ProductImportProcessor $processor,
    ): JsonResponse {
        $this->authorize('master.manage');

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $parsed = $parser->parse($request->file('file')->getRealPath());
        $result = $processor->run($parsed, dryRun: false);

        return response()->json([
            'summary' => $result['summary'],
            'rows' => $result['rows'],
            'fatal_errors' => $result['fatal_errors'],
        ]);
    }
}
