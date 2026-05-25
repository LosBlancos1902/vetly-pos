<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\StockTransferItem;
use App\Models\Tenant\Warehouse;
use App\Exceptions\InsufficientStockException;
use App\Services\JournalEngine;
use App\Services\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Transfer antar gudang 2-step + Barang Dalam Perjalanan (BDP).
 *
 * Flow:
 *   ship (in_transit) → receive (completed, bisa partial)
 *
 * Jurnal:
 *   ship    → D 1203 BDP / C 1201 Persediaan = Σ qty_sent × cost_at_transfer
 *   receive → D 1201 (tujuan) + D 5100 (loss) / C 1203 BDP
 *             (selisih qty_sent - qty_received = kerugian transit)
 *
 * HPP dest recalc via StockMovement engine (moving avg) — JANGAN reinvent.
 *
 * Concurrency:
 *   - Lock inventory rows source dalam canonical order (product_id ASC) saat ship
 *   - Lock transfer row saat receive (cegah double-receive)
 *   - SO freeze guard di kedua sisi (source saat ship, dest saat receive)
 */
class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockMovement $stock,
        private readonly JournalEngine $journal,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('inventory.transfer');

        $filters = $request->validate([
            'source_warehouse_id' => ['nullable', 'integer'],
            'dest_warehouse_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:in_transit,completed,cancelled'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = StockTransfer::query()
            ->with([
                'sourceWarehouse:id,code,name',
                'destWarehouse:id,code,name',
                'shipper:id,name',
                'receiver:id,name',
            ])
            ->withCount('items');

        // WarehouseScope manual: supervisor (warehouse_id != null) cuma boleh
        // lihat transfer yg involve warehouse-nya (source ATAU dest).
        $user = Auth::user();
        if ($user->warehouse_id !== null) {
            $query->where(function ($q) use ($user) {
                $q->where('source_warehouse_id', $user->warehouse_id)
                    ->orWhere('dest_warehouse_id', $user->warehouse_id);
            });
        }

        if (! empty($filters['source_warehouse_id'])) {
            $query->where('source_warehouse_id', $filters['source_warehouse_id']);
        }
        if (! empty($filters['dest_warehouse_id'])) {
            $query->where('dest_warehouse_id', $filters['dest_warehouse_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->where('shipped_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('shipped_at', '<=', $filters['to'].' 23:59:59');
        }

        return Inertia::render('Inventory/StockTransfers', [
            'transfers' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'warehouses' => Warehouse::query()->withoutGlobalScopes()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'filters' => [
                'source_warehouse_id' => $filters['source_warehouse_id'] ?? null,
                'dest_warehouse_id' => $filters['dest_warehouse_id'] ?? null,
                'status' => $filters['status'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'currentUserWarehouseId' => $user->warehouse_id,
        ]);
    }

    public function show(StockTransfer $transfer): Response
    {
        $this->authorize('inventory.transfer');
        $this->guardWarehouseAccess($transfer);

        $transfer->load([
            'sourceWarehouse:id,code,name',
            'destWarehouse:id,code,name',
            'shipper:id,name',
            'receiver:id,name',
            'items.product:id,sku,name,type',
            'journalShip:id,journal_no,date',
            'journalReceive:id,journal_no,date',
        ]);

        $user = Auth::user();
        // Tombol "Terima" hanya muncul kalau user punya akses ke warehouse tujuan
        // (warehouse_id null = owner/manager bisa terima atas nama cabang).
        $canReceive = $transfer->status === StockTransfer::STATUS_IN_TRANSIT
            && ($user->warehouse_id === null
                || $user->warehouse_id === $transfer->dest_warehouse_id);

        return Inertia::render('Inventory/StockTransferReceive', [
            'transfer' => $transfer,
            'canReceive' => $canReceive,
        ]);
    }

    /**
     * Product search untuk modal create transfer. Include semua tipe yang
     * track inventory (exclude service murni). Pola sama dgn Adjustment.
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $this->authorize('inventory.transfer');

        $q = trim((string) $request->query('q', ''));
        $sourceWarehouseId = (int) $request->integer('source_warehouse_id');

        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $products = Product::query()
            ->where('is_active', true)
            ->where('type', '!=', Product::TYPE_SERVICE)
            ->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'sku', 'name', 'type', 'base_unit_id', 'cost_avg']);

        // Attach source stock + cost untuk preview.
        $stockMap = $sourceWarehouseId
            ? Inventory::query()->withoutGlobalScopes()
                ->where('warehouse_id', $sourceWarehouseId)
                ->whereIn('product_id', $products->pluck('id'))
                ->get(['product_id', 'qty', 'cost_avg'])
                ->keyBy('product_id')
            : collect();

        $results = $products->map(function ($p) use ($stockMap) {
            $inv = $stockMap->get($p->id);

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'type' => $p->type,
                'source_qty' => $inv ? (float) $inv->qty : 0.0,
                'source_cost_avg' => $inv ? (float) $inv->cost_avg : (float) $p->cost_avg,
            ];
        });

        return response()->json(['results' => $results]);
    }

    /**
     * SHIP — bikin transfer + langsung kirim (in_transit).
     * 1 transaction: lock source → record transfer_out per item → post jurnal.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('inventory.transfer');

        $data = $request->validate([
            'source_warehouse_id' => ['required', 'integer', 'exists:warehouses,id',
                'different:dest_warehouse_id'],
            'dest_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $source = Warehouse::query()->withoutGlobalScopes()
            ->findOrFail($data['source_warehouse_id']);
        $dest = Warehouse::query()->withoutGlobalScopes()
            ->findOrFail($data['dest_warehouse_id']);

        if (! $source->is_active || ! $dest->is_active) {
            abort(422, 'Gudang asal/tujuan harus aktif.');
        }

        // Dedupe: 1 produk per transfer (schema unique).
        $pids = array_map(fn ($i) => (int) $i['product_id'], $data['items']);
        if (count($pids) !== count(array_unique($pids))) {
            abort(422, 'Satu produk tidak boleh muncul lebih dari sekali dalam satu transfer.');
        }

        // SO freeze guard di source.
        $frozenSource = StockOpname::frozenContextFor($source->id);
        foreach ($pids as $pid) {
            if (isset($frozenSource[$pid])) {
                $opnameId = $frozenSource[$pid];
                abort(422, "Produk #{$pid} sedang opname di gudang asal (SO #{$opnameId}). Selesaikan/batalkan opname dulu.");
            }
        }

        $transfer = DB::transaction(function () use ($source, $dest, $data, $pids, $request) {
            $transferNo = $this->generateTransferNo();

            $transfer = StockTransfer::create([
                'transfer_no' => $transferNo,
                'source_warehouse_id' => $source->id,
                'dest_warehouse_id' => $dest->id,
                'status' => StockTransfer::STATUS_IN_TRANSIT,
                'shipped_at' => now(),
                'shipped_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]);

            // Lock source inventory canonical (product_id ASC) — cegah deadlock
            // antar transfer parallel yg overlap produk.
            $pidsAsc = $pids;
            sort($pidsAsc);
            foreach ($pidsAsc as $pid) {
                Inventory::query()->withoutGlobalScopes()
                    ->where('product_id', $pid)
                    ->where('warehouse_id', $source->id)
                    ->lockForUpdate()
                    ->first();
            }

            $totalSent = 0.0;

            foreach ($data['items'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $qty = (float) $line['qty'];

                // Snapshot source cost_avg POST-lock.
                $srcInv = Inventory::query()->withoutGlobalScopes()
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $source->id)
                    ->first();
                $costAtTransfer = $srcInv ? (float) $srcInv->cost_avg : (float) ($product->cost_avg ?? 0);

                // Hard guard over-transfer regardless of user perm. Engine's
                // mayOverride() honors pos.sell.stock_minus (owner has via '*'),
                // tapi untuk TRANSFER over-stock NEVER masuk akal — barang nyata
                // tidak ada. Pre-check di sini sebelum engine call.
                $available = $srcInv ? (float) $srcInv->qty : 0.0;
                if ($qty > $available) {
                    throw new InsufficientStockException(
                        productId: $product->id,
                        warehouseId: $source->id,
                        availableBaseQty: (string) $available,
                        requestedBaseQty: (string) $qty,
                    );
                }

                $this->stock->record(
                    product: $product,
                    warehouse: $source,
                    type: 'transfer_out',
                    qty: $qty,
                    cost: $costAtTransfer,
                    options: [
                        'ref_type' => StockTransfer::class,
                        'ref_id' => $transfer->id,
                        'notes' => "Transfer {$transferNo} → {$dest->name}",
                    ],
                );

                $transfer->items()->create([
                    'product_id' => $product->id,
                    'qty_sent' => $qty,
                    'cost_at_transfer' => $costAtTransfer,
                ]);

                $totalSent += $qty * $costAtTransfer;
            }

            // Post jurnal ship aggregate. Skip kalau total=0 (cost_avg=0 produk baru).
            if ($totalSent > 0) {
                $journal = $this->journal->postTransferShip(
                    ref: $transferNo,
                    amount: $totalSent,
                    refId: $transfer->id,
                );
                $transfer->update(['journal_ship_id' => $journal->id]);
            }

            return $transfer;
        });

        return redirect()
            ->route('inventory.transfers.show', $transfer)
            ->with('success', "Transfer {$transfer->transfer_no} dikirim. Menunggu konfirmasi penerimaan.");
    }

    /**
     * RECEIVE — confirm penerimaan di gudang tujuan.
     * Per item: input qty_received (≤ qty_sent). Selisih jadi kerugian transit.
     */
    public function receive(Request $request, StockTransfer $transfer): RedirectResponse
    {
        $this->authorize('inventory.transfer');
        $this->guardWarehouseAccess($transfer);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:stock_transfer_items,id'],
            'items.*.qty_received' => ['required', 'numeric', 'gte:0'],
            'items.*.variance_notes' => ['nullable', 'string', 'max:500'],
            'receive_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $linesById = collect($data['items'])->keyBy('id');

        DB::transaction(function () use ($transfer, $linesById, $data, $request) {
            // Re-fetch dgn lock — guard double-receive race.
            $transfer = StockTransfer::query()
                ->lockForUpdate()
                ->with(['items', 'destWarehouse', 'sourceWarehouse'])
                ->findOrFail($transfer->id);

            if ($transfer->status !== StockTransfer::STATUS_IN_TRANSIT) {
                abort(422, "Transfer {$transfer->transfer_no} berstatus '{$transfer->status}', tidak bisa diterima.");
            }

            $dest = $transfer->destWarehouse;
            $source = $transfer->sourceWarehouse;

            // Validate item set: semua transfer.items harus disubmit, qty_received ≤ qty_sent.
            foreach ($transfer->items as $item) {
                if (! $linesById->has($item->id)) {
                    abort(422, "Item ID {$item->id} tidak ada di submission.");
                }
                $qtyR = (float) $linesById[$item->id]['qty_received'];
                if ($qtyR > (float) $item->qty_sent) {
                    abort(422, "qty_received ({$qtyR}) > qty_sent ({$item->qty_sent}) untuk item #{$item->id}.");
                }
            }

            // SO freeze guard di dest.
            $frozenDest = StockOpname::frozenContextFor($dest->id);
            foreach ($transfer->items as $item) {
                if (isset($frozenDest[$item->product_id])) {
                    $opnameId = $frozenDest[$item->product_id];
                    abort(422, "Produk #{$item->product_id} sedang opname di gudang tujuan (SO #{$opnameId}).");
                }
            }

            // Lock dest inventory canonical order.
            $pidsAsc = $transfer->items->pluck('product_id')->sort()->values()->all();
            foreach ($pidsAsc as $pid) {
                Inventory::query()->withoutGlobalScopes()
                    ->where('product_id', $pid)
                    ->where('warehouse_id', $dest->id)
                    ->lockForUpdate()
                    ->first(); // engine handle missing row
            }

            $totalReceived = 0.0;
            $totalLoss = 0.0;

            foreach ($transfer->items as $item) {
                $qtyR = (float) $linesById[$item->id]['qty_received'];
                $cost = (float) $item->cost_at_transfer;

                // Stock movement IN — hanya kalau qtyR > 0.
                // Porsi loss tidak masuk inventory dest (barang tidak pernah sampai).
                if ($qtyR > 0) {
                    $product = Product::findOrFail($item->product_id);
                    $this->stock->record(
                        product: $product,
                        warehouse: $dest,
                        type: 'transfer_in',
                        qty: $qtyR,
                        cost: $cost,
                        options: [
                            'ref_type' => StockTransfer::class,
                            'ref_id' => $transfer->id,
                            'notes' => "Transfer {$transfer->transfer_no} ← {$source->name}",
                        ],
                    );
                }

                $item->update([
                    'qty_received' => $qtyR,
                    'variance_notes' => $linesById[$item->id]['variance_notes'] ?? null,
                ]);

                $totalReceived += $qtyR * $cost;
                $totalLoss += ((float) $item->qty_sent - $qtyR) * $cost;
            }

            // Post jurnal receive (D 1201 / D 5100 / C 1203). Kalau totalReceived
            // + totalLoss = 0 (cost_avg=0 di semua items), jurnal di-skip.
            if ($totalReceived + $totalLoss > 0) {
                $journal = $this->journal->postTransferReceive(
                    ref: $transfer->transfer_no,
                    amountReceived: $totalReceived,
                    amountLoss: $totalLoss,
                    refId: $transfer->id,
                );
                $transfer->update(['journal_receive_id' => $journal->id]);
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_COMPLETED,
                'received_at' => now(),
                'received_by' => $request->user()->id,
                'receive_notes' => $data['receive_notes'] ?? null,
            ]);
        });

        return back()->with('success', "Transfer {$transfer->transfer_no} diterima.");
    }

    /**
     * Format TRF-YYYYMM-NNNN. Sequence per bulan, lockForUpdate untuk hindari race.
     * Pola identik dgn PO/GR/AP.
     */
    private function generateTransferNo(): string
    {
        $prefix = 'TRF-'.now()->format('Ym').'-';
        $lastSeq = StockTransfer::where('transfer_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->value('transfer_no');

        $next = $lastSeq ? ((int) substr($lastSeq, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Cek user punya akses ke transfer ini berdasarkan warehouse_id.
     * Supervisor (warehouse_id != null) hanya boleh lihat transfer yg involve
     * warehouse-nya. Owner/manager (null) bebas.
     */
    private function guardWarehouseAccess(StockTransfer $transfer): void
    {
        $user = Auth::user();
        if ($user->warehouse_id === null) {
            return;
        }
        if ($user->warehouse_id !== $transfer->source_warehouse_id
            && $user->warehouse_id !== $transfer->dest_warehouse_id) {
            abort(403, 'Anda tidak punya akses ke transfer ini.');
        }
    }
}
