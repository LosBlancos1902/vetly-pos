<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderItem;
use App\Models\Tenant\Warehouse;
use App\Services\JournalEngine;
use App\Services\StockMovement;
use App\Services\UnitConverter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Receiving (goods receipt) controller. Adalah titik kritis akuntansi —
 * receive sukses harus:
 *   1. Bertambah qty di `inventories` (atomic via StockMovement, base unit)
 *   2. Update HPP moving-average per produk (HppCalculator)
 *   3. Jurnal kebuat: D 1201 / C 1101 (cash) atau C 2101 (tempo)
 *   4. PO.status flip ke `received` kalau semua line fully received
 * Semua wrapped DB::transaction supaya gagal di mana saja → rollback bersih.
 */
class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly StockMovement $stock,
        private readonly UnitConverter $units,
        private readonly JournalEngine $journal,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('purchasing.receive');

        $receipts = GoodsReceipt::query()
            ->with([
                'purchaseOrder:id,po_no,supplier_id,payment_type,payment_term_days',
                'purchaseOrder.supplier:id,code,name',
                'warehouse:id,code,name',
                'receiver:id,name',
                'journal:id,journal_no',
                'items',
            ])
            ->when($request->po_id, fn ($q, $id) => $q->where('po_id', $id))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Purchasing/GoodsReceipts', [
            'receipts' => $receipts,
            'filters' => $request->only('po_id'),
            'receivablePos' => $this->receivablePos(),
        ]);
    }

    public function create(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        $this->authorize('purchasing.receive');

        if ($purchaseOrder->status !== PurchaseOrder::STATUS_APPROVED) {
            abort(422, "PO {$purchaseOrder->po_no} berstatus '{$purchaseOrder->status}', belum bisa diterima.");
        }

        $purchaseOrder->load([
            'supplier:id,code,name,payment_term_days',
            'warehouse:id,code,name',
            'items.product:id,sku,name',
            'items.unit:id,code,name',
        ]);

        return Inertia::render('Purchasing/GoodsReceiptCreate', [
            'po' => $purchaseOrder,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('purchasing.receive');

        $data = $request->validate([
            'po_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'received_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.po_item_id' => ['required', 'integer', 'exists:purchase_order_items,id'],
            'items.*.unit_id' => ['required', 'integer', 'exists:master_units,id'],
            'items.*.qty_received' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $po = PurchaseOrder::query()
            ->with(['items.product:id,sku,name,base_unit_id', 'items.unit:id'])
            ->findOrFail($data['po_id']);

        if ($po->status !== PurchaseOrder::STATUS_APPROVED) {
            abort(422, "PO {$po->po_no} berstatus '{$po->status}', hanya PO approved yang bisa diterima.");
        }

        $gr = DB::transaction(function () use ($po, $data, $request) {
            $warehouseId = $po->warehouse_id;
            $warehouse = Warehouse::findOrFail($warehouseId);

            $gr = GoodsReceipt::create([
                'gr_no' => $this->generateGrNo(),
                'po_id' => $po->id,
                'warehouse_id' => $warehouseId,
                'received_at' => $data['received_at'],
                'received_by' => $request->user()->id,
                'subtotal' => 0,
                'total' => 0,
                'notes' => $data['notes'] ?? null,
            ]);

            $runningTotal = 0.0;

            foreach ($data['items'] as $line) {
                /** @var PurchaseOrderItem $poItem */
                $poItem = $po->items->firstWhere('id', $line['po_item_id'])
                    ?? abort(422, "PO item #{$line['po_item_id']} tidak ditemukan di PO ini.");
                $product = $poItem->product;

                $qtyReceived = (float) $line['qty_received'];
                $unitPrice = (float) $poItem->unit_price; // snapshot dari PO

                // Convert received qty (in input unit) ke unit PO buat comparison.
                $receivedBase = $this->units->toBase($product, $qtyReceived, (int) $line['unit_id']);
                $receivedInPoUnit = $this->units->fromBase($product, $receivedBase, (int) $poItem->unit_id);

                $newReceivedInPoUnit = bcadd(
                    (string) $poItem->qty_received,
                    $receivedInPoUnit,
                    UnitConverter::SCALE,
                );

                if (bccomp($newReceivedInPoUnit, (string) $poItem->qty_ordered, UnitConverter::SCALE) === 1) {
                    abort(422, sprintf(
                        "Over-receive untuk produk %s: ordered %s, sudah %s, terima %s (semua dalam unit PO).",
                        $product->name,
                        $poItem->qty_ordered,
                        $poItem->qty_received,
                        $receivedInPoUnit,
                    ));
                }

                // Subtotal pakai qty_received (dalam input unit) * unit_price (per input unit).
                // unit_price dari PO adalah per PO-unit, jadi convert kalau berbeda.
                $unitPricePerInputUnit = $this->priceConvertedToInputUnit(
                    $product,
                    $unitPrice,
                    (int) $poItem->unit_id,
                    (int) $line['unit_id'],
                );
                $lineSubtotal = $qtyReceived * $unitPricePerInputUnit;
                $runningTotal += $lineSubtotal;

                // Cost per base unit untuk moving-average. Total value / base qty.
                $baseQtyFloat = (float) $receivedBase;
                $costPerBase = $baseQtyFloat > 0
                    ? $lineSubtotal / $baseQtyFloat
                    : 0.0;

                $gr->items()->create([
                    'po_item_id' => $poItem->id,
                    'product_id' => $product->id,
                    'unit_id' => $line['unit_id'],
                    'qty_received' => $qtyReceived,
                    'unit_price' => $unitPricePerInputUnit,
                    'subtotal' => $lineSubtotal,
                ]);

                // Catat stock movement (base unit). Auto-update inventory + cost_avg.
                $this->stock->record(
                    product: $product,
                    warehouse: $warehouse,
                    type: 'purchase',
                    qty: (float) $receivedBase,
                    cost: $costPerBase,
                    options: [
                        'ref_type' => GoodsReceipt::class,
                        'ref_id' => $gr->id,
                        'notes' => "GR {$gr->gr_no} dari PO {$po->po_no}",
                    ],
                );

                // Update qty_received di PO item (dalam unit PO).
                $poItem->update(['qty_received' => $newReceivedInPoUnit]);
            }

            $gr->update([
                'subtotal' => $runningTotal,
                'total' => $runningTotal,
            ]);

            // Post jurnal: D 1201 / C 1101 (cash) atau C 2101 (tempo).
            $journal = $this->journal->postPurchase(
                ref: $gr->gr_no,
                amount: $runningTotal,
                paymentType: $po->payment_type,
                refId: $gr->id,
            );
            $gr->update(['journal_id' => $journal->id]);

            // Cek apakah semua PO items fully received → flip PO.status.
            $po->refresh();
            $allReceived = $po->items()->get()->every(
                fn ($it) => bccomp((string) $it->qty_received, (string) $it->qty_ordered, UnitConverter::SCALE) >= 0,
            );
            if ($allReceived) {
                $po->update(['status' => PurchaseOrder::STATUS_RECEIVED]);
            }

            return $gr;
        });

        return redirect()
            ->route('purchasing.receipts.index')
            ->with('success', "Penerimaan {$gr->gr_no} dicatat.");
    }

    /**
     * Convert unit price dari unit-A ke unit-B untuk produk tertentu.
     * Harga per unit-A → harga per unit-B = harga-per-A * (faktor-A / faktor-B).
     */
    private function priceConvertedToInputUnit(
        Product $product,
        float $pricePerSourceUnit,
        int $sourceUnitId,
        int $targetUnitId,
    ): float {
        if ($sourceUnitId === $targetUnitId) {
            return $pricePerSourceUnit;
        }
        // 1 source unit → ? base
        $sourceBase = $this->units->toBase($product, 1, $sourceUnitId);
        // 1 target unit → ? base
        $targetBase = $this->units->toBase($product, 1, $targetUnitId);

        if (bccomp($sourceBase, '0', UnitConverter::SCALE) === 0) {
            return $pricePerSourceUnit;
        }
        // price-per-base = pricePerSource / sourceBase
        // price-per-target = pricePerBase * targetBase
        $pricePerBase = $pricePerSourceUnit / (float) $sourceBase;

        return $pricePerBase * (float) $targetBase;
    }

    private function receivablePos(): array
    {
        return PurchaseOrder::query()
            ->where('status', PurchaseOrder::STATUS_APPROVED)
            ->with(['supplier:id,code,name'])
            ->orderBy('id', 'desc')
            ->get(['id', 'po_no', 'supplier_id', 'payment_type', 'payment_term_days'])
            ->toArray();
    }

    private function generateGrNo(): string
    {
        $prefix = 'GR-'.now()->format('Ym').'-';
        $lastSeq = GoodsReceipt::where('gr_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->value('gr_no');

        $next = $lastSeq ? ((int) substr($lastSeq, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
