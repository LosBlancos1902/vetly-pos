<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Warehouse;
use App\Services\Reports\ColumnPicker;
use App\Services\Reports\ReportExcelExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan Persediaan READ-ONLY dengan column picker.
 */
class InventoryReportController extends Controller
{
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
        $cols = $this->columnsValuation();

        if ($request->boolean('export')) {
            [$labels, $extractors] = ColumnPicker::pick($cols, $this->selectedColumns($request));
            $data = ColumnPicker::rowsToArray($rows, $extractors);

            return (new ReportExcelExporter)
                ->addSheet('Nilai Stok', $labels, $data)
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
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

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

        $cols = $this->columnsMinStock();

        if ($request->boolean('export')) {
            [$labels, $extractors] = ColumnPicker::pick($cols, $this->selectedColumns($request));
            $data = ColumnPicker::rowsToArray($rows, $extractors);

            return (new ReportExcelExporter)
                ->addSheet('Stok Minimum', $labels, $data)
                ->download('stok-minimum_'.CarbonImmutable::now()->format('Ymd').'.xlsx');
        }

        return Inertia::render('Reports/Inventory/MinStock', [
            'filters' => $this->filtersOut($f),
            'warehouses' => $this->warehousesList(),
            'rows' => $rows,
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

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

        $cols = $this->columnsMovements();

        if ($request->boolean('export')) {
            $rows = $q->limit(50000)->get([
                'm.id', 'm.created_at', 'm.type',
                'p.sku', 'p.name as product_name',
                'w.code as warehouse_code', 'w.name as warehouse_name',
                'm.qty', 'm.cost', 'm.balance_qty_after', 'm.balance_cost_after',
                'm.ref_type', 'm.ref_id', 'm.reason', 'm.notes',
                'u.name as user_name',
            ]);
            [$labels, $extractors] = ColumnPicker::pick($cols, $this->selectedColumns($request));
            $data = ColumnPicker::rowsToArray($rows, $extractors);

            return (new ReportExcelExporter)
                ->addSheet('Mutasi Stok', $labels, $data)
                ->download('mutasi-stok_'.CarbonImmutable::parse($f['from'])->format('Ymd').'_'.CarbonImmutable::parse($f['to'])->format('Ymd').'.xlsx');
        }

        // Explicit kolom utk paginate — p.type & m.type bentrok kalau SELECT *.
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
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    // ───────────────────────── columns ─────────────────────────

    private function columnsValuation(): array
    {
        return [
            'warehouse_code' => ['label' => 'Kode Cabang', 'default' => false, 'value' => fn ($r) => $r->warehouse_code],
            'warehouse_name' => ['label' => 'Cabang', 'default' => true, 'value' => fn ($r) => $r->warehouse_name],
            'sku' => ['label' => 'SKU', 'default' => true, 'value' => fn ($r) => $r->sku],
            'product_name' => ['label' => 'Produk', 'default' => true, 'value' => fn ($r) => $r->product_name],
            'type' => ['label' => 'Tipe Produk', 'default' => false, 'value' => fn ($r) => $r->type],
            'qty' => ['label' => 'Qty', 'default' => true, 'value' => fn ($r) => (float) $r->qty],
            'cost_avg' => ['label' => 'Cost Rata-rata', 'default' => true, 'value' => fn ($r) => (float) $r->cost_avg],
            'nilai' => ['label' => 'Nilai Stok', 'default' => true, 'value' => fn ($r) => (float) $r->nilai],
        ];
    }

    private function columnsMinStock(): array
    {
        return [
            'warehouse_code' => ['label' => 'Kode Cabang', 'default' => false, 'value' => fn ($r) => $r->warehouse_code],
            'warehouse_name' => ['label' => 'Cabang', 'default' => true, 'value' => fn ($r) => $r->warehouse_name],
            'sku' => ['label' => 'SKU', 'default' => true, 'value' => fn ($r) => $r->sku],
            'product_name' => ['label' => 'Produk', 'default' => true, 'value' => fn ($r) => $r->product_name],
            'qty' => ['label' => 'Qty Sekarang', 'default' => true, 'value' => fn ($r) => (float) $r->qty],
            'min_stock' => ['label' => 'Min Stock', 'default' => true, 'value' => fn ($r) => (float) $r->min_stock],
            'max_stock' => ['label' => 'Max Stock', 'default' => false, 'value' => fn ($r) => $r->max_stock !== null ? (float) $r->max_stock : ''],
            'shortage' => ['label' => 'Kekurangan', 'default' => true, 'value' => fn ($r) => (float) $r->shortage],
        ];
    }

    private function columnsMovements(): array
    {
        return [
            'created_at' => ['label' => 'Waktu', 'default' => true, 'value' => fn ($r) => $r->created_at],
            'type' => ['label' => 'Tipe', 'default' => true, 'value' => fn ($r) => $r->type],
            'sku' => ['label' => 'SKU', 'default' => true, 'value' => fn ($r) => $r->sku],
            'product_name' => ['label' => 'Produk', 'default' => true, 'value' => fn ($r) => $r->product_name],
            'warehouse_code' => ['label' => 'Kode Cabang', 'default' => false, 'value' => fn ($r) => $r->warehouse_code],
            'warehouse_name' => ['label' => 'Cabang', 'default' => true, 'value' => fn ($r) => $r->warehouse_name],
            'qty' => ['label' => 'Qty', 'default' => true, 'value' => fn ($r) => (float) $r->qty],
            'cost' => ['label' => 'Cost', 'default' => true, 'value' => fn ($r) => (float) $r->cost],
            'balance_qty_after' => ['label' => 'Saldo Qty', 'default' => true, 'value' => fn ($r) => (float) $r->balance_qty_after],
            'balance_cost_after' => ['label' => 'Saldo Cost', 'default' => false, 'value' => fn ($r) => (float) $r->balance_cost_after],
            'ref_type' => ['label' => 'Ref Type', 'default' => true, 'value' => fn ($r) => $r->ref_type ?? ''],
            'ref_id' => ['label' => 'Ref ID', 'default' => false, 'value' => fn ($r) => $r->ref_id ?? ''],
            'reason' => ['label' => 'Reason', 'default' => false, 'value' => fn ($r) => $r->reason ?? ''],
            'notes' => ['label' => 'Notes', 'default' => false, 'value' => fn ($r) => $r->notes ?? ''],
            'user_name' => ['label' => 'User', 'default' => true, 'value' => fn ($r) => $r->user_name ?? ''],
        ];
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * @return array<int, string>|null
     */
    private function selectedColumns(Request $request): ?array
    {
        $cols = $request->input('columns');
        if (! is_array($cols)) {
            return null;
        }

        return array_values(array_filter($cols, fn ($v) => is_string($v) && $v !== ''));
    }

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
