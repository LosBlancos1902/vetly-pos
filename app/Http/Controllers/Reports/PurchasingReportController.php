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
 * Laporan Pembelian READ-ONLY.
 *
 * Endpoints:
 *   - index: Pembelian per supplier/produk (aggregated dari goods_receipt_items)
 *   - apAging: AP Aging — bucket umur hutang
 *   - apList: Daftar AP semua (termasuk paid), filter periode received_at + status
 */
class PurchasingReportController extends Controller
{
    private const VALID_DIMS = ['supplier', 'produk'];

    public function index(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.purchasing.view');
        $f = $this->parseFilters($request);
        $dim = $request->string('dim')->toString() === 'produk' ? 'produk' : 'supplier';

        $rows = $this->purchaseRows($dim, $f);
        $cols = $this->columnsPurchase($dim);

        if ($request->boolean('export')) {
            return $this->exportPurchases($dim, $rows, $cols, $f, $this->selectedColumns($request));
        }

        return Inertia::render('Reports/Purchasing/Index', [
            'filters' => array_merge($this->filtersOut($f), ['dim' => $dim]),
            'warehouses' => $this->warehousesList(),
            'rows' => $rows,
            'dims' => self::VALID_DIMS,
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    public function apAging(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.purchasing.view');

        $asOf = $request->date('as_of')
            ? CarbonImmutable::parse($request->date('as_of'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $rows = DB::table('accounts_payable as ap')
            ->join('suppliers as s', 's.id', '=', 'ap.supplier_id')
            ->leftJoin('goods_receipts as gr', 'gr.id', '=', 'ap.gr_id')
            ->whereIn('ap.status', ['open', 'partially_paid'])
            ->where('ap.amount', '>', DB::raw('ap.paid_amount'))
            ->orderBy('ap.due_date')
            ->get([
                'ap.id', 'ap.ap_no', 'ap.due_date',
                'ap.amount', 'ap.paid_amount', 'ap.status',
                's.id as supplier_id', 's.code as supplier_code', 's.name as supplier_name',
                'gr.gr_no', 'gr.received_at',
            ])
            ->map(function ($r) use ($asOf) {
                $due = $r->due_date ? CarbonImmutable::parse($r->due_date) : null;
                $daysOverdue = $due ? $due->diffInDays($asOf, false) : null;

                $bucket = '0-30';
                if ($daysOverdue !== null) {
                    if ($daysOverdue > 90) {
                        $bucket = '>90';
                    } elseif ($daysOverdue > 60) {
                        $bucket = '61-90';
                    } elseif ($daysOverdue > 30) {
                        $bucket = '31-60';
                    }
                }

                $remaining = (float) bcsub((string) $r->amount, (string) $r->paid_amount, 2);

                return (object) [
                    'ap_id' => (int) $r->id,
                    'ap_no' => $r->ap_no,
                    'supplier_id' => (int) $r->supplier_id,
                    'supplier_code' => $r->supplier_code,
                    'supplier_name' => $r->supplier_name,
                    'gr_no' => $r->gr_no,
                    'received_at' => $r->received_at,
                    'due_date' => $r->due_date,
                    'amount' => (float) $r->amount,
                    'paid_amount' => (float) $r->paid_amount,
                    'remaining' => $remaining,
                    'days_overdue' => $daysOverdue,
                    'bucket' => $bucket,
                    'status' => $r->status,
                ];
            })
            ->all();

        $buckets = [
            '0-30' => 0.0,
            '31-60' => 0.0,
            '61-90' => 0.0,
            '>90' => 0.0,
        ];
        foreach ($rows as $r) {
            $buckets[$r->bucket] += $r->remaining;
        }

        $cols = $this->columnsApAging();

        if ($request->boolean('export')) {
            return $this->exportApAging($rows, $buckets, $asOf, $cols, $this->selectedColumns($request));
        }

        return Inertia::render('Reports/Purchasing/ApAging', [
            'filters' => ['as_of' => $asOf->toDateString()],
            'rows' => $rows,
            'buckets' => $buckets,
            'total_outstanding' => array_sum($buckets),
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    /**
     * Daftar SEMUA AP (termasuk paid/void), filter periode received_at + status.
     * Berbeda dengan apAging yang hanya outstanding.
     */
    public function apList(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.purchasing.view');
        $f = $this->parsePeriod($request);
        $statusFilter = $request->string('status')->toString() ?: null;

        $q = DB::table('accounts_payable as ap')
            ->join('suppliers as s', 's.id', '=', 'ap.supplier_id')
            ->leftJoin('goods_receipts as gr', 'gr.id', '=', 'ap.gr_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'ap.po_id')
            ->whereBetween('gr.received_at', [$f['from']->toDateString(), $f['to']->toDateString()])
            ->orderBy('gr.received_at', 'desc')
            ->orderBy('ap.id', 'desc');

        if ($statusFilter) {
            $q->where('ap.status', $statusFilter);
        }

        $rows = $q->get([
            'ap.id', 'ap.ap_no', 'ap.due_date', 'ap.amount', 'ap.paid_amount', 'ap.status',
            's.code as supplier_code', 's.name as supplier_name',
            'gr.gr_no', 'gr.received_at',
            'po.po_no',
        ])->map(function ($r) {
            $r->amount = (float) $r->amount;
            $r->paid_amount = (float) $r->paid_amount;
            $r->remaining = round($r->amount - $r->paid_amount, 2);

            return $r;
        })->all();

        $cols = $this->columnsApList();

        if ($request->boolean('export')) {
            [$labels, $extractors] = ColumnPicker::pick($cols, $this->selectedColumns($request));
            $data = ColumnPicker::rowsToArray($rows, $extractors);

            return (new ReportExcelExporter)
                ->addSheet('Daftar AP', $labels, $data)
                ->addSheet('Info', ['Item', 'Nilai'], [
                    ['Periode terima', $f['from']->toDateString().' s/d '.$f['to']->toDateString()],
                    ['Filter status', $statusFilter ?? 'SEMUA'],
                    ['Total record', count($rows)],
                ])
                ->download('daftar-ap_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
        }

        return Inertia::render('Reports/Purchasing/ApList', [
            'filters' => [
                'from' => $f['from']->toDateString(),
                'to' => $f['to']->toDateString(),
                'status' => $statusFilter,
            ],
            'rows' => $rows,
            'available_columns' => ColumnPicker::publicMeta($cols),
            'status_options' => ['open', 'partially_paid', 'paid', 'void'],
        ]);
    }

    // ───────────────────────── column defs ─────────────────────────

    private function columnsPurchase(string $dim): array
    {
        $dimLabel = $dim === 'produk' ? 'Produk' : 'Supplier';

        return [
            'code' => ['label' => "Kode {$dimLabel}", 'default' => true, 'value' => fn ($r) => $r['code']],
            'label' => ['label' => $dimLabel, 'default' => true, 'value' => fn ($r) => $r['label']],
            'trx_count' => ['label' => 'Jumlah Penerimaan', 'default' => true, 'value' => fn ($r) => $r['trx_count']],
            'qty' => ['label' => 'Total Qty', 'default' => true, 'value' => fn ($r) => (float) $r['qty']],
            'nilai' => ['label' => 'Nilai Pembelian', 'default' => true, 'value' => fn ($r) => (float) $r['nilai']],
        ];
    }

    private function columnsApAging(): array
    {
        return [
            'ap_no' => ['label' => 'No AP', 'default' => true, 'value' => fn ($r) => $r->ap_no],
            'supplier_code' => ['label' => 'Kode Supplier', 'default' => false, 'value' => fn ($r) => $r->supplier_code],
            'supplier_name' => ['label' => 'Supplier', 'default' => true, 'value' => fn ($r) => $r->supplier_name],
            'gr_no' => ['label' => 'No GR', 'default' => false, 'value' => fn ($r) => $r->gr_no ?? ''],
            'received_at' => ['label' => 'Tgl Terima', 'default' => false, 'value' => fn ($r) => $r->received_at ?? ''],
            'due_date' => ['label' => 'Jatuh Tempo', 'default' => true, 'value' => fn ($r) => $r->due_date ?? ''],
            'amount' => ['label' => 'Nilai', 'default' => true, 'value' => fn ($r) => $r->amount],
            'paid_amount' => ['label' => 'Sudah Bayar', 'default' => false, 'value' => fn ($r) => $r->paid_amount],
            'remaining' => ['label' => 'Sisa', 'default' => true, 'value' => fn ($r) => $r->remaining],
            'days_overdue' => ['label' => 'Hari Overdue (+ = overdue)', 'default' => true, 'value' => fn ($r) => $r->days_overdue],
            'bucket' => ['label' => 'Bucket', 'default' => true, 'value' => fn ($r) => $r->bucket],
            'status' => ['label' => 'Status', 'default' => false, 'value' => fn ($r) => $r->status],
        ];
    }

    private function columnsApList(): array
    {
        return [
            'ap_no' => ['label' => 'No AP', 'default' => true, 'value' => fn ($r) => $r->ap_no],
            'received_at' => ['label' => 'Tgl Terima', 'default' => true, 'value' => fn ($r) => $r->received_at ?? ''],
            'supplier_code' => ['label' => 'Kode Supplier', 'default' => false, 'value' => fn ($r) => $r->supplier_code],
            'supplier_name' => ['label' => 'Supplier', 'default' => true, 'value' => fn ($r) => $r->supplier_name],
            'po_no' => ['label' => 'No PO', 'default' => false, 'value' => fn ($r) => $r->po_no ?? ''],
            'gr_no' => ['label' => 'No GR', 'default' => true, 'value' => fn ($r) => $r->gr_no ?? ''],
            'amount' => ['label' => 'Nilai', 'default' => true, 'value' => fn ($r) => $r->amount],
            'paid_amount' => ['label' => 'Sudah Bayar', 'default' => true, 'value' => fn ($r) => $r->paid_amount],
            'remaining' => ['label' => 'Sisa', 'default' => true, 'value' => fn ($r) => $r->remaining],
            'due_date' => ['label' => 'Jatuh Tempo', 'default' => true, 'value' => fn ($r) => $r->due_date ?? ''],
            'status' => ['label' => 'Status', 'default' => true, 'value' => fn ($r) => $r->status],
        ];
    }

    // ───────────────────────── aggregation ─────────────────────────

    private function purchaseRows(string $dim, array $f): array
    {
        $q = DB::table('goods_receipt_items as gri')
            ->join('goods_receipts as gr', 'gr.id', '=', 'gri.gr_id')
            ->whereBetween('gr.received_at', [
                CarbonImmutable::parse($f['from'])->toDateString(),
                CarbonImmutable::parse($f['to'])->toDateString(),
            ]);

        if ($f['warehouse_id']) {
            $q->where('gr.warehouse_id', $f['warehouse_id']);
        }

        if ($dim === 'produk') {
            $q->join('products as p', 'p.id', '=', 'gri.product_id')
                ->groupBy('p.id', 'p.sku', 'p.name')
                ->selectRaw('p.id as key_id, p.sku as code, p.name as label, '
                    .'COUNT(DISTINCT gr.id) as trx_count, '
                    .'SUM(gri.qty_received) as qty, '
                    .'SUM(gri.subtotal) as nilai');
        } else {
            $q->join('purchase_orders as po', 'po.id', '=', 'gr.po_id')
                ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
                ->groupBy('s.id', 's.code', 's.name')
                ->selectRaw('s.id as key_id, s.code as code, s.name as label, '
                    .'COUNT(DISTINCT gr.id) as trx_count, '
                    .'SUM(gri.qty_received) as qty, '
                    .'SUM(gri.subtotal) as nilai');
        }

        return $q->orderByDesc('nilai')->get()
            ->map(fn ($r) => [
                'key_id' => (int) $r->key_id,
                'code' => $r->code ?: '',
                'label' => $r->label,
                'trx_count' => (int) $r->trx_count,
                'qty' => (float) $r->qty,
                'nilai' => (float) $r->nilai,
            ])
            ->all();
    }

    // ───────────────────────── exporters ─────────────────────────

    private function exportPurchases(string $dim, array $rows, array $cols, array $f, ?array $selected): BinaryFileResponse
    {
        [$labels, $extractors] = ColumnPicker::pick($cols, $selected);
        $data = ColumnPicker::rowsToArray($rows, $extractors);

        $fromS = CarbonImmutable::parse($f['from'])->format('Ymd');
        $toS = CarbonImmutable::parse($f['to'])->format('Ymd');

        return (new ReportExcelExporter)
            ->addSheet('Pembelian per '.ucfirst($dim), $labels, $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Dimensi', $dim],
                ['Periode', CarbonImmutable::parse($f['from'])->toDateString().' s/d '.CarbonImmutable::parse($f['to'])->toDateString()],
                ['Cabang', $f['warehouse_id'] ? (string) $f['warehouse_id'] : 'KONSOLIDASI'],
                ['Sumber', 'goods_receipt_items (event penerimaan)'],
            ])
            ->download('pembelian-per-'.$dim.'_'.$fromS.'_'.$toS.'.xlsx');
    }

    private function exportApAging(array $rows, array $buckets, CarbonImmutable $asOf, array $cols, ?array $selected): BinaryFileResponse
    {
        [$labels, $extractors] = ColumnPicker::pick($cols, $selected);
        $detail = ColumnPicker::rowsToArray($rows, $extractors);

        return (new ReportExcelExporter)
            ->addSheet('Detail AP', $labels, $detail)
            ->addSheet('Ringkasan Bucket', ['Bucket', 'Total Sisa'], [
                ['0-30 (belum jatuh tempo / overdue ≤30 hari)', $buckets['0-30']],
                ['31-60', $buckets['31-60']],
                ['61-90', $buckets['61-90']],
                ['>90', $buckets['>90']],
                ['TOTAL', array_sum($buckets)],
                ['Per Tanggal', $asOf->toDateString()],
            ])
            ->download('ap-aging_'.$asOf->format('Ymd').'.xlsx');
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
        $whId = $user->warehouse_id ?? $requestedWh;

        return [
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'warehouse_id' => $whId,
        ];
    }

    /**
     * Simpler period parser untuk apList (no warehouse scope di AP — AP itu
     * supplier-level, bukan warehouse-level).
     *
     * @return array{from:CarbonImmutable,to:CarbonImmutable}
     */
    private function parsePeriod(Request $request): array
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

        return ['from' => $from, 'to' => $to];
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
