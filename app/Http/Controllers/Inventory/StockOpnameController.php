<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\PendingStockMovement;
use App\Models\Tenant\Product;
use App\Models\Tenant\Sale;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\StockOpnameItem;
use App\Models\Tenant\Warehouse;
use App\Services\JournalEngine;
use App\Services\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Stock Opname (stock-take / opname fisik).
 *
 * Flow:
 *   1. create → snapshot inventory.qty per produk yg trackable di gudang
 *   2. updateItems → user input qty_physical (qty_diff auto-compute)
 *   3. complete → SATU transaction:
 *      - per item dengan adjustment != 0 (qty_physical - current_qty):
 *          stock movement adjustment_plus/minus + inventory naik/turun
 *      - kumpulin totalPlus & totalMinus → 2 jurnal aggregate
 *      - status = completed
 *
 * Jurnal:
 *   plus  → D 1201 Persediaan / C 5100 HPP
 *   minus → D 5100 HPP        / C 1201 Persediaan
 *
 * Note: adjustment dihitung dari (qty_physical - inventory.qty_saat_complete),
 * BUKAN qty_diff (yang berbasis snapshot). Untuk opname yg dilakukan di waktu
 * tenang (no concurrent sales) keduanya sama; tapi kalau ada transaksi di
 * tengah, yang valid adalah current_qty terhadap physical count.
 */
class StockOpnameController extends Controller
{
    public function __construct(
        private readonly StockMovement $stock,
        private readonly JournalEngine $journal,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('inventory.opname');

        $opnames = StockOpname::query()
            ->with([
                'warehouse:id,code,name',
                'creator:id,name',
                'completer:id,name',
                'items',
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->warehouse_id, fn ($q, $w) => $q->where('warehouse_id', $w))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Inventory/StockOpnames', [
            'opnames' => $opnames,
            'filters' => $request->only('status', 'warehouse_id'),
            'warehouses' => Warehouse::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('inventory.opname');

        return Inertia::render('Inventory/StockOpnameCreate', [
            'warehouses' => Warehouse::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name']),
            'defaultWarehouseId' => $request->user()->warehouse_id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('inventory.opname');

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'opname_date' => ['required', 'date'],
            'catatan' => ['nullable', 'string'],
        ]);

        // Concurrency guard: max 1 SO aktif (draft/counting) per warehouse.
        // Karena pending sale di-attach ke opname tertentu, overlap akan
        // bikin ambiguity siapa yang ngangkat pending.
        if ($activeId = StockOpname::activeOpnameIdFor((int) $data['warehouse_id'])) {
            $active = StockOpname::find($activeId);
            abort(422, "Sudah ada opname aktif di gudang ini: {$active->opname_no} ({$active->status}). Selesaikan atau batalkan dulu.");
        }

        $opname = DB::transaction(function () use ($data, $request) {
            $opname = StockOpname::create([
                'opname_no' => $this->generateOpnameNo(),
                'warehouse_id' => $data['warehouse_id'],
                'status' => StockOpname::STATUS_DRAFT,
                'opname_date' => $data['opname_date'],
                'catatan' => $data['catatan'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            // Snapshot semua inventory rows untuk warehouse + produk yg trackable
            // (bukan service). Inventory pakai global scope ke warehouse current
            // user — bypass dengan withoutGlobalScopes karena kita target spesifik
            // warehouse via WHERE.
            $rows = Inventory::query()
                ->withoutGlobalScopes()
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->where('inventories.warehouse_id', $data['warehouse_id'])
                ->where('products.is_active', true)
                ->whereNotIn('products.type', [Product::TYPE_SERVICE])
                ->select(
                    'inventories.product_id',
                    'inventories.qty as qty_system',
                )
                ->get();

            foreach ($rows as $r) {
                $opname->items()->create([
                    'product_id' => $r->product_id,
                    'qty_system' => $r->qty_system,
                    'qty_physical' => null,
                    'qty_diff' => null,
                ]);
            }

            return $opname;
        });

        return redirect()
            ->route('inventory.opnames.show', $opname->id)
            ->with('success', "Opname {$opname->opname_no} dibuat dengan ".$opname->items()->count().' produk.');
    }

    public function show(Request $request, StockOpname $opname): Response
    {
        $this->authorize('inventory.opname');

        $opname->load([
            'warehouse:id,code,name',
            'creator:id,name',
            'completer:id,name',
            'items.product:id,sku,name,base_unit_id',
            'items.product.baseUnit:id,code,name',
        ]);

        // Ringkasan pending penjualan yang akan diapply pas complete —
        // dipakai confirm dialog di UI biar user paham impact sebelum klik.
        $pendingAgg = PendingStockMovement::query()
            ->where('opname_id', $opname->id)
            ->whereNull('applied_at')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(qty_base * cost_per_base), 0) as total_cogs')
            ->first();

        return Inertia::render('Inventory/StockOpnameCounting', [
            'opname' => $opname,
            'pendingSummary' => [
                'count' => (int) ($pendingAgg->count ?? 0),
                'total_cogs' => (float) ($pendingAgg->total_cogs ?? 0),
            ],
        ]);
    }

    public function updateItems(Request $request, StockOpname $opname): RedirectResponse
    {
        $this->authorize('inventory.opname');

        if (! in_array($opname->status, [StockOpname::STATUS_DRAFT, StockOpname::STATUS_COUNTING], true)) {
            abort(422, "Opname berstatus '{$opname->status}', tidak bisa diubah.");
        }

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:stock_opname_items,id'],
            'items.*.qty_physical' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($opname, $data) {
            foreach ($data['items'] as $row) {
                $item = StockOpnameItem::where('id', $row['id'])
                    ->where('opname_id', $opname->id)
                    ->firstOrFail();

                $physical = $row['qty_physical'] ?? null;
                $diff = $physical !== null
                    ? (float) bcsub((string) $physical, (string) $item->qty_system, 4)
                    : null;

                $item->update([
                    'qty_physical' => $physical,
                    'qty_diff' => $diff,
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            // Auto-transition draft → counting setelah ada input.
            if ($opname->status === StockOpname::STATUS_DRAFT) {
                $opname->update(['status' => StockOpname::STATUS_COUNTING]);
            }
        });

        return back()->with('success', 'Hasil hitung disimpan.');
    }

    public function complete(Request $request, StockOpname $opname): RedirectResponse
    {
        $this->authorize('inventory.opname');

        if (! in_array($opname->status, [StockOpname::STATUS_DRAFT, StockOpname::STATUS_COUNTING], true)) {
            abort(422, "Opname berstatus '{$opname->status}', tidak bisa di-complete.");
        }

        $opname->load('items', 'warehouse');
        $warehouse = $opname->warehouse;

        $itemsWithPhysical = $opname->items->whereNotNull('qty_physical');
        if ($itemsWithPhysical->isEmpty()) {
            abort(422, 'Belum ada item yang dihitung — minimal 1 item harus diinput qty fisik.');
        }

        DB::transaction(function () use ($opname, $warehouse, $itemsWithPhysical, $request) {
            $totalPlus = 0.0;
            $totalMinus = 0.0;

            foreach ($itemsWithPhysical as $item) {
                /** @var StockOpnameItem $item */
                $product = Product::findOrFail($item->product_id);

                // Lock current inventory row untuk consistent read + write.
                $inv = Inventory::query()
                    ->withoutGlobalScopes()
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->lockForUpdate()
                    ->first();

                $currentQty = $inv ? (float) $inv->qty : 0.0;
                $costAvg = $inv ? (float) $inv->cost_avg : (float) ($product->cost_avg ?? 0);
                $targetQty = (float) $item->qty_physical;
                $adjustment = $targetQty - $currentQty;

                // Tolerance untuk float comparison.
                if (abs($adjustment) < 0.0001) {
                    continue;
                }

                $absAdj = abs($adjustment);
                $amount = $absAdj * $costAvg;

                $isPlus = $adjustment > 0;
                $type = $isPlus ? 'adjustment_plus' : 'adjustment_minus';

                $this->stock->record(
                    product: $product,
                    warehouse: $warehouse,
                    type: $type,
                    qty: $absAdj,
                    cost: $costAvg,
                    options: [
                        'ref_type' => StockOpname::class,
                        'ref_id' => $opname->id,
                        'notes' => "Opname {$opname->opname_no}",
                        'allow_minus' => true, // opname is authoritative
                    ],
                );

                if ($isPlus) {
                    $totalPlus += $amount;
                } else {
                    $totalMinus += $amount;
                }
            }

            // Post jurnal aggregate per arah (phase a — adjustment fisik vs sistem).
            if ($totalPlus > 0) {
                $this->journal->postAdjustment($opname->opname_no, $totalPlus, true);
            }
            if ($totalMinus > 0) {
                $this->journal->postAdjustment($opname->opname_no, $totalMinus, false);
            }

            // ─── Phase (b): process pending stock movements untuk SO ini ──────
            // Pending dibuat saat penjualan POS terhadap produk yang sedang
            // di-snap (frozen) selama SO aktif. Sekarang setelah adjustment
            // fisik di-apply, baru pending kita "lepas" → potong stok + post
            // HPP penjualan deferred (D 5100 / C 1201) sebagai jurnal terpisah.
            $deferredCogs = $this->applyPendingMovements($opname, $warehouse);

            if ($deferredCogs > 0) {
                $this->journal->postDeferredCogs($opname->opname_no, $deferredCogs, $opname->id);
            }

            $opname->update([
                'status' => StockOpname::STATUS_COMPLETED,
                'completed_by' => $request->user()->id,
                'completed_at' => now(),
            ]);
        });

        return back()->with('success', "Opname {$opname->opname_no} selesai.");
    }

    public function cancel(Request $request, StockOpname $opname): RedirectResponse
    {
        $this->authorize('inventory.opname');

        if (! in_array($opname->status, [StockOpname::STATUS_DRAFT, StockOpname::STATUS_COUNTING], true)) {
            abort(422, "Opname berstatus '{$opname->status}', tidak bisa dibatalkan.");
        }

        $data = $request->validate([
            'cancelled_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $opname->update([
            'status' => StockOpname::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_reason' => $data['cancelled_reason'],
        ]);

        return back()->with('success', "Opname {$opname->opname_no} dibatalkan.");
    }

    /**
     * Apply semua pending stock movements untuk SO ini:
     *   - Record real stock_movements (potong inventory, update cost_avg)
     *   - Link pending.applied_at + applied_movement_id
     *   - Return total deferred COGS untuk jurnal aggregate.
     */
    private function applyPendingMovements(StockOpname $opname, Warehouse $warehouse): float
    {
        $pendings = PendingStockMovement::where('opname_id', $opname->id)
            ->whereNull('applied_at')
            ->get();

        if ($pendings->isEmpty()) {
            return 0.0;
        }

        $deferredCogs = 0.0;

        foreach ($pendings as $pending) {
            $product = Product::findOrFail($pending->product_id);
            $qty = (float) $pending->qty_base;
            $costPerBase = (float) $pending->cost_per_base;

            $movement = $this->stock->record(
                product: $product,
                warehouse: $warehouse,
                type: $pending->type, // 'sale' atau 'service_consumption'
                qty: $qty,
                cost: $costPerBase,
                options: [
                    'ref_type' => Sale::class,
                    'ref_id' => (int) $pending->sale_id,
                    'notes' => "Pending dari opname {$opname->opname_no} (sale #{$pending->sale_id})",
                ],
            );

            $pending->update([
                'applied_at' => now(),
                'applied_movement_id' => $movement->id,
            ]);

            $deferredCogs += $qty * $costPerBase;
        }

        return round($deferredCogs, 2);
    }

    /**
     * Download Excel template untuk SO: kode, nama, satuan, qty_system,
     * qty_fisik (kolom kosong). Diisi offline oleh petugas.
     */
    public function downloadExcel(Request $request, StockOpname $opname): BinaryFileResponse
    {
        $this->authorize('inventory.opname');

        $opname->load(['items.product:id,sku,name,base_unit_id', 'items.product.baseUnit:id,code']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Opname');

        // PhpSpreadsheet 5.x: setCellValueByColumnAndRow / getStyleByColumnAndRow
        // dihapus. Pakai string coordinate (A1, B1, ...) — kolom kita cuma 5,
        // jadi letter-hardcode paling readable.
        $headers = ['Kode', 'Nama Produk', 'Satuan', 'Qty Sistem', 'Qty Fisik'];
        $columns = ['A', 'B', 'C', 'D', 'E'];
        foreach ($headers as $i => $h) {
            $cell = $columns[$i].'1';
            $sheet->setCellValue($cell, $h);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        $row = 2;
        foreach ($opname->items as $it) {
            $sheet->setCellValue('A'.$row, $it->product->sku);
            $sheet->setCellValue('B'.$row, $it->product->name);
            $sheet->setCellValue('C'.$row, $it->product->baseUnit?->code ?? '');
            $sheet->setCellValue('D'.$row, (float) $it->qty_system);
            // Kolom E (Qty Fisik) sengaja kosong.
            $row++;
        }

        foreach ($columns as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = "opname-{$opname->opname_no}.xlsx";
        $tmpPath = tempnam(sys_get_temp_dir(), 'opname-').'.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($tmpPath);

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Upload Excel hasil opname: parse rows, match by Kode (SKU),
     * isi qty_physical + recompute qty_diff. Tidak meng-apply ke inventory
     * (itu di-trigger via complete() terpisah).
     */
    public function uploadExcel(Request $request, StockOpname $opname): RedirectResponse
    {
        $this->authorize('inventory.opname');

        if (! in_array($opname->status, [StockOpname::STATUS_DRAFT, StockOpname::STATUS_COUNTING], true)) {
            abort(422, "Opname berstatus '{$opname->status}', tidak bisa diupload.");
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:5120'],
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false); // numeric indexes 0..

        // Skip header.
        if (count($rows) < 2) {
            abort(422, 'File kosong atau header saja.');
        }
        array_shift($rows);

        // Build map [sku => qty_physical].
        $bySku = [];
        $skipped = 0;
        foreach ($rows as $r) {
            $sku = isset($r[0]) ? trim((string) $r[0]) : '';
            $qty = $r[4] ?? null;
            if ($sku === '' || $qty === null || $qty === '') {
                $skipped++;
                continue;
            }
            if (! is_numeric($qty)) {
                $skipped++;
                continue;
            }
            $bySku[$sku] = (float) $qty;
        }

        if ($bySku === []) {
            abort(422, 'Tidak ada baris dengan Qty Fisik yang valid.');
        }

        $opname->load('items.product:id,sku');
        $updated = 0;
        DB::transaction(function () use ($opname, $bySku, &$updated) {
            foreach ($opname->items as $item) {
                $sku = $item->product->sku ?? null;
                if ($sku && array_key_exists($sku, $bySku)) {
                    $physical = $bySku[$sku];
                    $diff = (float) bcsub((string) $physical, (string) $item->qty_system, 4);
                    $item->update([
                        'qty_physical' => $physical,
                        'qty_diff' => $diff,
                    ]);
                    $updated++;
                }
            }
            if ($opname->status === StockOpname::STATUS_DRAFT) {
                $opname->update(['status' => StockOpname::STATUS_COUNTING]);
            }
        });

        $msg = "Excel diimport: {$updated} item terisi";
        if ($skipped > 0) {
            $msg .= " (skip {$skipped} baris kosong/invalid)";
        }

        return back()->with('success', $msg);
    }

    private function generateOpnameNo(): string
    {
        $prefix = 'OPN-'.now()->format('Ym').'-';
        $lastSeq = StockOpname::where('opname_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->value('opname_no');

        $next = $lastSeq ? ((int) substr($lastSeq, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
