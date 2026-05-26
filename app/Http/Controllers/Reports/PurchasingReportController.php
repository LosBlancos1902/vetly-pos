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
 * Laporan Pembelian READ-ONLY.
 * Source: goods_receipts + goods_receipt_items + suppliers, accounts_payable.
 *
 * Pembelian dihitung dari GR (goods receipt), bukan PO — karena GR adalah
 * event yang menambah aset/utang. PO yang belum diterima tidak masuk.
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

        if ($request->boolean('export')) {
            return $this->exportPurchases($dim, $rows, $f);
        }

        return Inertia::render('Reports/Purchasing/Index', [
            'filters' => array_merge($this->filtersOut($f), ['dim' => $dim]),
            'warehouses' => $this->warehousesList(),
            'rows' => $rows,
            'dims' => self::VALID_DIMS,
        ]);
    }

    /**
     * AP Aging — bucket: 0-30 / 31-60 / 61-90 / >90 hari dari due_date.
     * Hanya AP berstatus open/partially_paid (sisa hutang > 0).
     * Bucket dihitung berdasarkan `as_of` (default hari ini).
     */
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
                // Bucket berdasarkan SELISIH HARI dari due_date ke as_of.
                // due_date di masa depan = "belum jatuh tempo" (0-30).
                // overdue dikategori berdasar lewat berapa hari.
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

        // Aggregate bucket
        $buckets = [
            '0-30' => 0.0,
            '31-60' => 0.0,
            '61-90' => 0.0,
            '>90' => 0.0,
        ];
        foreach ($rows as $r) {
            $buckets[$r->bucket] += $r->remaining;
        }

        if ($request->boolean('export')) {
            return $this->exportApAging($rows, $buckets, $asOf);
        }

        return Inertia::render('Reports/Purchasing/ApAging', [
            'filters' => ['as_of' => $asOf->toDateString()],
            'rows' => $rows,
            'buckets' => $buckets,
            'total_outstanding' => array_sum($buckets),
        ]);
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
        } else { // supplier
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
        // user fixed-to-WH = forced. Owner/manager = optional filter.
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

    // ───────────────────────── exporters ─────────────────────────

    private function exportPurchases(string $dim, array $rows, array $f): BinaryFileResponse
    {
        $headers = [ucfirst($dim).' (Kode)', ucfirst($dim).' (Nama)', 'Jumlah Penerimaan', 'Total Qty', 'Nilai Pembelian'];
        $data = array_map(fn ($r) => [
            $r['code'], $r['label'], $r['trx_count'], $r['qty'], $r['nilai'],
        ], $rows);

        return (new ReportExcelExporter)
            ->addSheet('Pembelian per '.ucfirst($dim), $headers, $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Dimensi', $dim],
                ['Periode', CarbonImmutable::parse($f['from'])->toDateString().' s/d '.CarbonImmutable::parse($f['to'])->toDateString()],
                ['Cabang', $f['warehouse_id'] ? (string) $f['warehouse_id'] : 'KONSOLIDASI'],
                ['Sumber', 'goods_receipt_items (event penerimaan)'],
            ])
            ->download('pembelian-per-'.$dim.'_'.CarbonImmutable::parse($f['from'])->format('Ymd').'_'.CarbonImmutable::parse($f['to'])->format('Ymd').'.xlsx');
    }

    private function exportApAging(array $rows, array $buckets, CarbonImmutable $asOf): BinaryFileResponse
    {
        // Sheet 1: detail per AP (flat)
        $detail = array_map(fn ($r) => [
            $r->ap_no, $r->supplier_code, $r->supplier_name, $r->gr_no ?? '',
            $r->received_at ?? '', $r->due_date ?? '',
            $r->amount, $r->paid_amount, $r->remaining,
            $r->days_overdue, $r->bucket, $r->status,
        ], $rows);

        return (new ReportExcelExporter)
            ->addSheet('Detail AP', [
                'No AP', 'Kode Supplier', 'Nama Supplier', 'No GR',
                'Tgl Terima', 'Jatuh Tempo',
                'Nilai', 'Sudah Bayar', 'Sisa',
                'Hari Overdue (+ = overdue)', 'Bucket', 'Status',
            ], $detail)
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
}
