<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Warehouse;
use App\Services\Reports\ReportExcelExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan Penjualan READ-ONLY. Source: sales + sales_items + relasi.
 * Konsisten dengan P&L: hanya sale.status='completed' (void dikecualikan).
 *
 * Multi-dimensi: produk / kategori / pelanggan / kasir / cabang.
 * Margin: subtotal − (qty × cost_snapshot) per item, group by produk/kategori.
 *
 * WarehouseScope:
 *   - Owner/manager (warehouse_id NULL) → konsolidasi default, filter cabang opsional
 *   - Supervisor/cashier (warehouse_id != NULL) → forced ke warehouse mereka
 */
class SalesReportController extends Controller
{
    private const VALID_DIMS = ['produk', 'kategori', 'pelanggan', 'kasir', 'cabang'];

    public function index(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.sales.view');
        $f = $this->parseFilters($request);
        $dim = in_array($request->string('dim')->toString(), self::VALID_DIMS, true)
            ? $request->string('dim')->toString()
            : 'produk';

        $rows = $this->aggregateBy($dim, $f);

        if ($request->boolean('export')) {
            return $this->exportSales($dim, $rows, $f);
        }

        return Inertia::render('Reports/Sales/MultiDim', [
            'filters' => array_merge($this->filtersOut($f), ['dim' => $dim]),
            'warehouses' => $this->warehousesList($f),
            'rows' => $rows,
            'dims' => self::VALID_DIMS,
        ]);
    }

    /**
     * Margin per produk/kategori. Pakai cost_snapshot di sales_items (sudah
     * di-freeze saat sale, jadi tidak terpengaruh kalau cost_avg berubah
     * setelahnya).
     */
    public function margin(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.sales.view');
        $f = $this->parseFilters($request);
        $dim = $request->string('dim')->toString() === 'kategori' ? 'kategori' : 'produk';

        $rows = $this->marginRows($dim, $f);

        if ($request->boolean('export')) {
            return $this->exportMargin($dim, $rows, $f);
        }

        return Inertia::render('Reports/Sales/Margin', [
            'filters' => array_merge($this->filtersOut($f), ['dim' => $dim]),
            'warehouses' => $this->warehousesList($f),
            'rows' => $rows,
        ]);
    }

    // ───────────────────────── aggregation ─────────────────────────

    private function aggregateBy(string $dim, array $f): array
    {
        $q = DB::table('sales_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.date', [$f['from'], $f['to']]);

        if ($f['warehouse_id']) {
            $q->where('s.warehouse_id', $f['warehouse_id']);
        }

        // group + select sesuai dim
        switch ($dim) {
            case 'produk':
                $q->join('products as p', 'p.id', '=', 'si.product_id')
                    ->groupBy('p.id', 'p.sku', 'p.name')
                    ->selectRaw('p.id as key_id, p.sku as code, p.name as label, '
                        .'COUNT(DISTINCT s.id) as trx_count, '
                        .'SUM(si.qty) as qty, '
                        .'SUM(si.subtotal) as omzet');
                break;
            case 'kategori':
                $q->join('products as p', 'p.id', '=', 'si.product_id')
                    ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                    ->groupBy('c.id', 'c.name')
                    ->selectRaw('COALESCE(c.id,0) as key_id, "" as code, '
                        .'COALESCE(c.name, "(tanpa kategori)") as label, '
                        .'COUNT(DISTINCT s.id) as trx_count, '
                        .'SUM(si.qty) as qty, '
                        .'SUM(si.subtotal) as omzet');
                break;
            case 'pelanggan':
                $q->leftJoin('customers as cu', 'cu.id', '=', 's.customer_id')
                    ->groupBy('cu.id', 'cu.code', 'cu.name')
                    ->selectRaw('COALESCE(cu.id,0) as key_id, COALESCE(cu.code,"-") as code, '
                        .'COALESCE(cu.name, "(umum/tanpa customer)") as label, '
                        .'COUNT(DISTINCT s.id) as trx_count, '
                        .'SUM(si.qty) as qty, '
                        .'SUM(si.subtotal) as omzet');
                break;
            case 'kasir':
                $q->join('users as u', 'u.id', '=', 's.cashier_id')
                    ->groupBy('u.id', 'u.name')
                    ->selectRaw('u.id as key_id, "" as code, u.name as label, '
                        .'COUNT(DISTINCT s.id) as trx_count, '
                        .'SUM(si.qty) as qty, '
                        .'SUM(si.subtotal) as omzet');
                break;
            case 'cabang':
                $q->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
                    ->groupBy('w.id', 'w.code', 'w.name')
                    ->selectRaw('w.id as key_id, w.code as code, w.name as label, '
                        .'COUNT(DISTINCT s.id) as trx_count, '
                        .'SUM(si.qty) as qty, '
                        .'SUM(si.subtotal) as omzet');
                break;
        }

        return $q->orderByDesc('omzet')->get()
            ->map(fn ($r) => [
                'key_id' => (int) $r->key_id,
                'code' => $r->code ?: '',
                'label' => $r->label,
                'trx_count' => (int) $r->trx_count,
                'qty' => (float) $r->qty,
                'omzet' => (float) $r->omzet,
            ])
            ->all();
    }

    private function marginRows(string $dim, array $f): array
    {
        $q = DB::table('sales_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.date', [$f['from'], $f['to']]);

        if ($f['warehouse_id']) {
            $q->where('s.warehouse_id', $f['warehouse_id']);
        }

        if ($dim === 'kategori') {
            $q->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->groupBy('c.id', 'c.name')
                ->selectRaw('COALESCE(c.id,0) as key_id, "" as code, '
                    .'COALESCE(c.name, "(tanpa kategori)") as label, '
                    .'SUM(si.qty) as qty, '
                    .'SUM(si.subtotal) as omzet, '
                    .'SUM(si.qty * si.cost_snapshot) as hpp');
        } else {
            $q->groupBy('p.id', 'p.sku', 'p.name')
                ->selectRaw('p.id as key_id, p.sku as code, p.name as label, '
                    .'SUM(si.qty) as qty, '
                    .'SUM(si.subtotal) as omzet, '
                    .'SUM(si.qty * si.cost_snapshot) as hpp');
        }

        return $q->orderByDesc('omzet')->get()
            ->map(function ($r) {
                $omzet = (float) $r->omzet;
                $hpp = (float) $r->hpp;
                $margin = $omzet - $hpp;
                $pct = $omzet > 0 ? ($margin / $omzet) * 100 : 0;

                return [
                    'key_id' => (int) $r->key_id,
                    'code' => $r->code ?: '',
                    'label' => $r->label,
                    'qty' => (float) $r->qty,
                    'omzet' => $omzet,
                    'hpp' => $hpp,
                    'margin' => $margin,
                    'margin_pct' => round($pct, 2),
                ];
            })
            ->all();
    }

    // ───────────────────────── filter parsing ─────────────────────────

    /**
     * @return array{from:string,to:string,warehouse_id:?int}
     */
    private function parseFilters(Request $request): array
    {
        $now = CarbonImmutable::now();
        $from = $request->date('from')
            ? CarbonImmutable::parse($request->date('from'))->startOfDay()
            : $now->startOfMonth();
        $to = $request->date('to')
            ? CarbonImmutable::parse($request->date('to'))->endOfDay()
            : $now->endOfDay();
        if ($to->lt($from)) {
            $to = $from->endOfDay();
        }

        $user = Auth::user();
        $requestedWh = $request->integer('warehouse_id') ?: null;
        $whId = $user->warehouse_id ?? $requestedWh;
        // Saat user fixed-to-WH, abaikan param warehouse_id (anti-bypass).

        return [
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'warehouse_id' => $whId,
        ];
    }

    private function filtersOut(array $f): array
    {
        return [
            'from' => CarbonImmutable::parse($f['from'])->toDateString(),
            'to' => CarbonImmutable::parse($f['to'])->toDateString(),
            'warehouse_id' => $f['warehouse_id'],
        ];
    }

    private function warehousesList(array $f)
    {
        // Kalau user fixed-to-WH, dropdown disable di UI; tetap kembalikan
        // warehouse-nya supaya nama tampil.
        $user = Auth::user();
        if ($user->warehouse_id !== null) {
            return Warehouse::where('id', $user->warehouse_id)->get(['id', 'code', 'name']);
        }

        return Warehouse::active()->orderBy('name')->get(['id', 'code', 'name']);
    }

    // ───────────────────────── exporters ─────────────────────────

    private function exportSales(string $dim, array $rows, array $f): BinaryFileResponse
    {
        $headers = [ucfirst($dim).' (Kode)', ucfirst($dim).' (Nama)', 'Jumlah Transaksi', 'Total Qty', 'Omzet'];
        $data = array_map(fn ($r) => [
            $r['code'], $r['label'], $r['trx_count'], $r['qty'], $r['omzet'],
        ], $rows);

        return (new ReportExcelExporter)
            ->addSheet('Penjualan per '.ucfirst($dim), $headers, $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Dimensi', $dim],
                ['Periode', CarbonImmutable::parse($f['from'])->toDateString().' s/d '.CarbonImmutable::parse($f['to'])->toDateString()],
                ['Cabang', $f['warehouse_id'] ? (string) $f['warehouse_id'] : 'KONSOLIDASI'],
                ['Catatan', 'Hanya sale berstatus completed. Void dikecualikan.'],
            ])
            ->download('penjualan-per-'.$dim.'_'.CarbonImmutable::parse($f['from'])->format('Ymd').'_'.CarbonImmutable::parse($f['to'])->format('Ymd').'.xlsx');
    }

    private function exportMargin(string $dim, array $rows, array $f): BinaryFileResponse
    {
        $headers = [ucfirst($dim).' (Kode)', ucfirst($dim).' (Nama)', 'Qty', 'Omzet', 'HPP', 'Margin', 'Margin %'];
        $data = array_map(fn ($r) => [
            $r['code'], $r['label'], $r['qty'], $r['omzet'], $r['hpp'], $r['margin'], $r['margin_pct'],
        ], $rows);

        return (new ReportExcelExporter)
            ->addSheet('Margin per '.ucfirst($dim), $headers, $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Dimensi', $dim],
                ['Periode', CarbonImmutable::parse($f['from'])->toDateString().' s/d '.CarbonImmutable::parse($f['to'])->toDateString()],
                ['Cabang', $f['warehouse_id'] ? (string) $f['warehouse_id'] : 'KONSOLIDASI'],
                ['Sumber HPP', 'cost_snapshot di sales_items (frozen saat sale dibuat)'],
            ])
            ->download('margin-per-'.$dim.'_'.CarbonImmutable::parse($f['from'])->format('Ymd').'_'.CarbonImmutable::parse($f['to'])->format('Ymd').'.xlsx');
    }
}
