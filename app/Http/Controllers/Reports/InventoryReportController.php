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
 * Laporan Persediaan READ-ONLY.
 * Source: inventories (qty + cost_avg per WH), stock_movements, products.
 *
 * Nilai stok = qty × cost_avg (snapshot saat ini). Untuk "nilai stok per
 * tanggal lampau", butuh balance_cost_after × balance_qty_after dari
 * movement terakhir ≤ tanggal — bisa di-add nanti, untuk v1 cukup snapshot.
 */
class InventoryReportController extends Controller
{
    /**
     * Nilai Stok — qty × cost_avg per (produk, warehouse).
     * Total per warehouse + grand total.
     */
    public function valuation(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.inventory.view');
        $f = $this->parseFilters($request, withPeriod: false);

        $q = DB::table('inventories as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->where('p.is_active', true)
            ->orderBy('w.name')
            ->orderBy('p.name');

        if ($f['warehouse_id']) {
            $q->where('i.warehouse_id', $f['warehouse_id']);
        }
        if ($request->boolean('only_with_stock')) {
            $q->where('i.qty', '>', 0);
        }

        $rows = $q->get([
            'p.id as product_id', 'p.sku', 'p.name as product_name', 'p.type',
            'w.id as warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name',
            'i.qty', 'i.cost_avg',
        ])->map(function ($r) {
            $qty = (float) $r->qty;
            $cost = (float) $r->cost_avg;
            $r->qty = $qty;
            $r->cost_avg = $cost;
            $r->nilai = $qty * $cost;

            return $r;
        })->all();

        $totalNilai = array_sum(array_map(fn ($r) => $r->nilai, $rows));
        $totalQty = array_sum(array_map(fn ($r) => $r->qty, $rows));

        if ($request->boolean('export')) {
            return (new ReportExcelExporter)
                ->addSheet('Nilai Stok', [
                    'Kode WH', 'Nama WH', 'SKU', 'Nama Produk', 'Tipe',
                    'Qty', 'Cost Rata-rata', 'Nilai Stok',
                ], array_map(fn ($r) => [
                    $r->warehouse_code, $r->warehouse_name, $r->sku, $r->product_name, $r->type,
                    $r->qty, $r->cost_avg, $r->nilai,
                ], $rows))
                ->addSheet('Ringkasan', ['Item', 'Nilai'], [
                    ['Total Qty', $totalQty],
                    ['Total Nilai Stok', $totalNilai],
                    ['Cabang', $f['warehouse_id'] ? (string) $f['warehouse_id'] : 'KONSOLIDASI'],
                    ['Snapshot', CarbonImmutable::now()->toDateTimeString()],
                ])
                ->download('nilai-stok_'.CarbonImmutable::now()->format('Ymd').'.xlsx');
        }

        return Inertia::render('Reports/Inventory/Valuation', [
            'filters' => array_merge($this->filtersOut($f), [
                'only_with_stock' => $request->boolean('only_with_stock'),
            ]),
            'warehouses' => $this->warehousesList(),
            'rows' => $rows,
            'totals' => [
                'qty' => $totalQty,
                'nilai' => $totalNilai,
            ],
        ]);
    }

    /**
     * Stok Minimum — list inventory dimana qty ≤ min_stock (alert).
     */
    public function minStock(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.inventory.view');
        $f = $this->parseFilters($request, withPeriod: false);

        $q = DB::table('inventories as i')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->where('p.is_active', true)
            ->where('p.min_stock', '>', 0)
            ->whereColumn('i.qty', '<=', 'p.min_stock')
            ->orderByRaw('(i.qty - p.min_stock) ASC');

        if ($f['warehouse_id']) {
            $q->where('i.warehouse_id', $f['warehouse_id']);
        }

        $rows = $q->get([
            'p.id as product_id', 'p.sku', 'p.name as product_name',
            'w.id as warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name',
            'i.qty', 'p.min_stock', 'p.max_stock',
        ])->map(function ($r) {
            $r->qty = (float) $r->qty;
            $r->min_stock = (float) $r->min_stock;
            $r->max_stock = $r->max_stock !== null ? (float) $r->max_stock : null;
            $r->shortage = max(0, $r->min_stock - $r->qty);

            return $r;
        })->all();

        if ($request->boolean('export')) {
            return (new ReportExcelExporter)
                ->addSheet('Stok Minimum', [
                    'Kode WH', 'Nama WH', 'SKU', 'Nama Produk',
                    'Qty Sekarang', 'Min Stock', 'Max Stock', 'Kekurangan',
                ], array_map(fn ($r) => [
                    $r->warehouse_code, $r->warehouse_name, $r->sku, $r->product_name,
                    $r->qty, $r->min_stock, $r->max_stock, $r->shortage,
                ], $rows))
                ->download('stok-minimum_'.CarbonImmutable::now()->format('Ymd').'.xlsx');
        }

        return Inertia::render('Reports/Inventory/MinStock', [
            'filters' => $this->filtersOut($f),
            'warehouses' => $this->warehousesList(),
            'rows' => $rows,
        ]);
    }

    /**
     * Mutasi Stok periode — list dari stock_movements dengan filter
     * tanggal/warehouse/produk/type.
     *
     * Risk: periode panjang bisa berat. Default 30 hari, paginated 100/page
     * di UI. Export ambil semua tapi maksimum 50k row guard.
     */
    public function movements(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.inventory.view');
        $f = $this->parseFilters($request, withPeriod: true, defaultDays: 30);
        $type = $request->string('type')->toString() ?: null;
        $productId = $request->integer('product_id') ?: null;

        $q = DB::table('stock_movements as m')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->whereBetween('m.created_at', [$f['from'], $f['to']])
            ->orderBy('m.created_at')
            ->orderBy('m.id');

        if ($f['warehouse_id']) {
            $q->where('m.warehouse_id', $f['warehouse_id']);
        }
        if ($type) {
            $q->where('m.type', $type);
        }
        if ($productId) {
            $q->where('m.product_id', $productId);
        }

        if ($request->boolean('export')) {
            $rows = $q->limit(50000)->get([
                'm.id', 'm.created_at', 'm.type',
                'p.sku', 'p.name as product_name',
                'w.code as warehouse_code', 'w.name as warehouse_name',
                'm.qty', 'm.cost', 'm.balance_qty_after', 'm.balance_cost_after',
                'm.ref_type', 'm.ref_id', 'm.reason', 'm.notes',
                'u.name as user_name',
            ]);

            return (new ReportExcelExporter)
                ->addSheet('Mutasi Stok', [
                    'ID', 'Waktu', 'Tipe', 'SKU', 'Produk',
                    'Kode WH', 'Nama WH',
                    'Qty', 'Cost', 'Saldo Qty', 'Saldo Cost',
                    'Ref Type', 'Ref ID', 'Reason', 'Notes', 'User',
                ], $rows->map(fn ($r) => [
                    (int) $r->id, $r->created_at, $r->type, $r->sku, $r->product_name,
                    $r->warehouse_code, $r->warehouse_name,
                    (float) $r->qty, (float) $r->cost,
                    (float) $r->balance_qty_after, (float) $r->balance_cost_after,
                    $r->ref_type ?? '', $r->ref_id ?? '', $r->reason ?? '', $r->notes ?? '',
                    $r->user_name ?? '',
                ])->all())
                ->download('mutasi-stok_'.CarbonImmutable::parse($f['from'])->format('Ymd').'_'.CarbonImmutable::parse($f['to'])->format('Ymd').'.xlsx');
        }

        // Paginated untuk UI — explicit kolom, JANGAN SELECT * krn p.type & m.type
        // namanya sama dan p.type akan men-shadow m.type di hasil.
        $movements = $q->paginate(100, [
            'm.id', 'm.created_at', 'm.type',
            'm.product_id', 'p.sku', 'p.name as product_name',
            'm.warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name',
            'm.qty', 'm.cost', 'm.balance_qty_after', 'm.balance_cost_after',
            'm.ref_type', 'm.ref_id', 'm.reason', 'm.notes',
            'u.name as user_name',
        ])->withQueryString();

        return Inertia::render('Reports/Inventory/Movements', [
            'filters' => array_merge($this->filtersOut($f), [
                'type' => $type,
                'product_id' => $productId,
            ]),
            'warehouses' => $this->warehousesList(),
            'movements' => $movements,
            'movement_types' => [
                'purchase', 'sale', 'transfer_in', 'transfer_out',
                'adjustment_plus', 'adjustment_minus', 'return_in', 'return_out',
                'opname_plus', 'opname_minus', 'compound_in', 'compound_out',
                'service_consumption',
            ],
        ]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * @return array{from:string,to:string,warehouse_id:?int}
     */
    private function parseFilters(Request $request, bool $withPeriod = true, int $defaultDays = 30): array
    {
        $now = CarbonImmutable::now();
        $from = $request->date('from')
            ? CarbonImmutable::parse($request->date('from'))->startOfDay()
            : $now->subDays($defaultDays)->startOfDay();
        $to = $request->date('to')
            ? CarbonImmutable::parse($request->date('to'))->endOfDay()
            : $now->endOfDay();
        if ($to->lt($from)) {
            $to = $from->endOfDay();
        }

        $user = Auth::user();
        $requestedWh = $request->integer('warehouse_id') ?: null;
        $whId = $user->warehouse_id ?? $requestedWh;

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

    private function warehousesList()
    {
        $user = Auth::user();
        if ($user->warehouse_id !== null) {
            return Warehouse::where('id', $user->warehouse_id)->get(['id', 'code', 'name']);
        }

        return Warehouse::active()->orderBy('name')->get(['id', 'code', 'name']);
    }
}
