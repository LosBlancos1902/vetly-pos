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
 * Laporan Penjualan READ-ONLY.
 *
 * UI: aggregated multi-dim (per produk/kategori/pelanggan/kasir/cabang).
 * Export: DETAIL per sale item dengan kolom kaya (sesuai spec) + pilih kolom.
 * Margin: aggregated per produk/kategori (UI & export sama).
 *
 * WarehouseScope: user fixed-to-WH FORCED ke warehouse-nya (anti-bypass).
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

        $cols = $this->columnsSalesDetail();

        if ($request->boolean('export')) {
            return $this->exportSalesDetail($f, $cols, $this->selectedColumns($request));
        }

        $rows = $this->aggregateBy($dim, $f);

        return Inertia::render('Reports/Sales/MultiDim', [
            'filters' => array_merge($this->filtersOut($f), ['dim' => $dim]),
            'warehouses' => $this->warehousesList(),
            'rows' => $rows,
            'dims' => self::VALID_DIMS,
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    public function margin(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.sales.view');
        $f = $this->parseFilters($request);
        $dim = $request->string('dim')->toString() === 'kategori' ? 'kategori' : 'produk';

        $rows = $this->marginRows($dim, $f);
        $cols = $this->columnsMargin();

        if ($request->boolean('export')) {
            return $this->exportMargin($dim, $rows, $cols, $f, $this->selectedColumns($request));
        }

        return Inertia::render('Reports/Sales/Margin', [
            'filters' => array_merge($this->filtersOut($f), ['dim' => $dim]),
            'warehouses' => $this->warehousesList(),
            'rows' => $rows,
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    // ───────────────────────── columns ─────────────────────────

    private function columnsSalesDetail(): array
    {
        return [
            'invoice_no' => ['label' => 'No Invoice', 'default' => true, 'value' => fn ($r) => $r->invoice_no],
            'date' => ['label' => 'Tanggal', 'default' => true, 'value' => fn ($r) => $r->date],
            'warehouse_code' => ['label' => 'Kode Cabang', 'default' => false, 'value' => fn ($r) => $r->warehouse_code],
            'warehouse_name' => ['label' => 'Cabang', 'default' => true, 'value' => fn ($r) => $r->warehouse_name],
            'cashier_name' => ['label' => 'Kasir', 'default' => true, 'value' => fn ($r) => $r->cashier_name],
            'customer_code' => ['label' => 'Kode Pelanggan', 'default' => false, 'value' => fn ($r) => $r->customer_code ?? ''],
            'customer_name' => ['label' => 'Pelanggan', 'default' => true, 'value' => fn ($r) => $r->customer_name ?? '(umum)'],
            'sku' => ['label' => 'SKU', 'default' => true, 'value' => fn ($r) => $r->sku],
            'product_name' => ['label' => 'Produk', 'default' => true, 'value' => fn ($r) => $r->product_name],
            'category_name' => ['label' => 'Kategori', 'default' => false, 'value' => fn ($r) => $r->category_name ?? ''],
            'qty' => ['label' => 'Qty (base)', 'default' => true, 'value' => fn ($r) => (float) $r->qty],
            'unit_code' => ['label' => 'Satuan', 'default' => false, 'value' => fn ($r) => $r->unit_code ?? ''],
            'price' => ['label' => 'Harga Satuan', 'default' => true, 'value' => fn ($r) => (float) $r->price],
            'item_discount' => ['label' => 'Diskon Item', 'default' => false, 'value' => fn ($r) => (float) $r->item_discount],
            'item_subtotal' => ['label' => 'Subtotal Item', 'default' => true, 'value' => fn ($r) => (float) $r->item_subtotal],
            'cost_snapshot' => ['label' => 'HPP per Unit', 'default' => false, 'value' => fn ($r) => (float) $r->cost_snapshot],
            'sale_total' => ['label' => 'Total Sale', 'default' => true, 'value' => fn ($r) => (float) $r->sale_total],
            'payment_method' => ['label' => 'Metode Bayar', 'default' => true, 'value' => fn ($r) => $r->payment_method ?? ''],
            'payment_status' => ['label' => 'Status Bayar', 'default' => false, 'value' => fn ($r) => $r->payment_status],
            'status' => ['label' => 'Status Sale', 'default' => false, 'value' => fn ($r) => $r->status],
        ];
    }

    private function columnsMargin(): array
    {
        return [
            'code' => ['label' => 'Kode', 'default' => true, 'value' => fn ($r) => $r['code']],
            'label' => ['label' => 'Nama', 'default' => true, 'value' => fn ($r) => $r['label']],
            'qty' => ['label' => 'Qty', 'default' => true, 'value' => fn ($r) => (float) $r['qty']],
            'omzet' => ['label' => 'Omzet', 'default' => true, 'value' => fn ($r) => (float) $r['omzet']],
            'hpp' => ['label' => 'HPP', 'default' => true, 'value' => fn ($r) => (float) $r['hpp']],
            'margin' => ['label' => 'Margin', 'default' => true, 'value' => fn ($r) => (float) $r['margin']],
            'margin_pct' => ['label' => 'Margin %', 'default' => true, 'value' => fn ($r) => (float) $r['margin_pct']],
        ];
    }

    // ───────────────────────── aggregation (UI) ─────────────────────────

    private function aggregateBy(string $dim, array $f): array
    {
        $q = DB::table('sales_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.date', [$f['from'], $f['to']]);

        if ($f['warehouse_id']) {
            $q->where('s.warehouse_id', $f['warehouse_id']);
        }

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

    // ───────────────────────── exporters ─────────────────────────

    private function exportSalesDetail(array $f, array $cols, ?array $selected): BinaryFileResponse
    {
        // 1 baris = 1 sale_item. JOIN ke semua master untuk kaya kolom.
        $q = DB::table('sales as s')
            ->join('sales_items as si', 'si.sale_id', '=', 's.id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('customers as cu', 'cu.id', '=', 's.customer_id')
            ->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->join('users as u', 'u.id', '=', 's.cashier_id')
            ->leftJoin('master_units as mu', 'mu.id', '=', 'si.unit_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.date', [$f['from'], $f['to']])
            ->orderBy('s.date')
            ->orderBy('s.id')
            ->orderBy('si.id');

        if ($f['warehouse_id']) {
            $q->where('s.warehouse_id', $f['warehouse_id']);
        }

        $rows = $q->limit(50000)->get([
            's.invoice_no', 's.date',
            'w.code as warehouse_code', 'w.name as warehouse_name',
            'u.name as cashier_name',
            'cu.code as customer_code', 'cu.name as customer_name',
            'p.sku', 'p.name as product_name', 'c.name as category_name',
            'si.qty', 'mu.code as unit_code',
            'si.price', 'si.discount_amount as item_discount',
            'si.subtotal as item_subtotal', 'si.cost_snapshot',
            's.total as sale_total', 's.payment_method', 's.payment_status', 's.status',
        ]);

        [$labels, $extractors] = ColumnPicker::pick($cols, $selected);
        $data = ColumnPicker::rowsToArray($rows, $extractors);

        $fromS = CarbonImmutable::parse($f['from'])->format('Ymd');
        $toS = CarbonImmutable::parse($f['to'])->format('Ymd');

        return (new ReportExcelExporter)
            ->addSheet('Detail Penjualan', $labels, $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Periode', CarbonImmutable::parse($f['from'])->toDateString().' s/d '.CarbonImmutable::parse($f['to'])->toDateString()],
                ['Cabang', $f['warehouse_id'] ? (string) $f['warehouse_id'] : 'KONSOLIDASI'],
                ['Sumber', 'sale_items level — 1 baris = 1 item per invoice'],
                ['Catatan', 'Hanya sale berstatus completed (void dikecualikan)'],
            ])
            ->download('penjualan-detail_'.$fromS.'_'.$toS.'.xlsx');
    }

    private function exportMargin(string $dim, array $rows, array $cols, array $f, ?array $selected): BinaryFileResponse
    {
        [$labels, $extractors] = ColumnPicker::pick($cols, $selected);
        $data = ColumnPicker::rowsToArray($rows, $extractors);

        $fromS = CarbonImmutable::parse($f['from'])->format('Ymd');
        $toS = CarbonImmutable::parse($f['to'])->format('Ymd');

        return (new ReportExcelExporter)
            ->addSheet('Margin per '.ucfirst($dim), $labels, $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Dimensi', $dim],
                ['Periode', CarbonImmutable::parse($f['from'])->toDateString().' s/d '.CarbonImmutable::parse($f['to'])->toDateString()],
                ['Cabang', $f['warehouse_id'] ? (string) $f['warehouse_id'] : 'KONSOLIDASI'],
                ['Sumber HPP', 'cost_snapshot di sales_items (frozen saat sale dibuat)'],
            ])
            ->download('margin-per-'.$dim.'_'.$fromS.'_'.$toS.'.xlsx');
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
        // Anti-bypass: user fixed-to-WH FORCED ke warehouse-nya, request diabaikan.
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
