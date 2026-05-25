<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\Warehouse;
use App\Services\JournalEngine;
use App\Services\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Penyesuaian Persediaan (manual stock adjustment).
 *
 * Berbeda dari Stock Opname:
 *   - SO = stock-take periodik, varians fisik vs sistem (multi-item per session)
 *   - Adjustment = koreksi sporadis 1 produk 1 gudang (rusak/hilang/expired/koreksi)
 *
 * Jurnal IDENTIK dengan SO (pakai JournalEngine::postAdjustment):
 *   plus  → D 1201 Persediaan / C 5100 HPP
 *   minus → D 5100 HPP        / C 1201 Persediaan
 * Amount = qty × inventories.cost_avg (per-warehouse, BUKAN Product.cost_avg).
 *
 * Kategori (reason) tidak mengubah COA — visibility hidup di stock_movements.reason.
 * Owner bisa filter listing by reason untuk lihat shrinkage per kategori.
 *
 * Concurrency: tolak adjust kalau produk sedang frozen oleh SO aktif di warehouse
 * sama — adjust mid-snapshot akan merusak qty_physical baseline opname.
 */
class AdjustmentController extends Controller
{
    public function __construct(
        private readonly StockMovement $stock,
        private readonly JournalEngine $journal,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('inventory.adjustment');

        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'reason' => ['nullable', Rule::in(['rusak', 'hilang', 'expired', 'koreksi'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = StockMovementModel::query()
            ->whereIn('type', ['adjustment_plus', 'adjustment_minus'])
            ->where('ref_type', 'manual_adjustment')
            ->with([
                'product:id,sku,name',
                'warehouse:id,code,name',
                'user:id,name',
            ]);

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['reason'])) {
            $query->where('reason', $filters['reason']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        return Inertia::render('Inventory/Adjustments', [
            'movements' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'warehouses' => Warehouse::query()->withoutGlobalScopes()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'reasonLabels' => [
                'rusak' => 'Rusak',
                'hilang' => 'Hilang',
                'expired' => 'Kadaluwarsa',
                'koreksi' => 'Koreksi',
            ],
            'filters' => [
                'warehouse_id' => $filters['warehouse_id'] ?? null,
                'reason' => $filters['reason'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
        ]);
    }

    /**
     * Product search untuk adjustment modal. Beda dari Cashier search:
     * include raw_material (komponen rusak/expired juga perlu di-adjust),
     * exclude service murni (tidak punya stok).
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $this->authorize('inventory.adjustment');

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $products = Product::query()
            ->where('is_active', true)
            ->where('type', '!=', Product::TYPE_SERVICE) // service murni no-stock
            ->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'sku', 'name', 'type', 'cost_avg']);

        return response()->json(['results' => $products]);
    }

    /**
     * Preview HPP × qty sebelum submit. JSON only.
     * Frontend memanggil saat user pilih produk+gudang+qty untuk tampilkan
     * estimasi nilai jurnal yang akan ke-post.
     */
    public function preview(Request $request)
    {
        $this->authorize('inventory.adjustment');

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'qty' => ['required', 'numeric'],
        ]);

        $inv = Inventory::query()->withoutGlobalScopes()
            ->where('product_id', $data['product_id'])
            ->where('warehouse_id', $data['warehouse_id'])
            ->first();

        $costAvg = $inv ? (float) $inv->cost_avg : 0.0;
        $currentQty = $inv ? (float) $inv->qty : 0.0;
        $absQty = abs((float) $data['qty']);

        return response()->json([
            'cost_avg' => $costAvg,
            'current_qty' => $currentQty,
            'amount' => round($absQty * $costAvg, 2),
            'is_plus' => (float) $data['qty'] > 0,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('inventory.adjustment');

        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'qty' => ['required', 'numeric', 'not_in:0'],
            'reason' => ['required', Rule::in(['rusak', 'hilang', 'expired', 'koreksi'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $warehouse = Warehouse::query()->withoutGlobalScopes()
            ->findOrFail($data['warehouse_id']);

        // SO freeze guard — pola sama dengan SO::store() blocking concurrent SO.
        // Adjust ke produk frozen akan ngecacatin qty_physical baseline opname.
        $frozen = StockOpname::frozenContextFor($warehouse->id);
        if (isset($frozen[$product->id])) {
            $opnameId = $frozen[$product->id];
            abort(422, "Produk ini sedang opname (SO #{$opnameId}) — selesaikan atau batalkan dulu opname-nya.");
        }

        $isPlus = (float) $data['qty'] > 0;
        $type = $isPlus ? 'adjustment_plus' : 'adjustment_minus';
        $absQty = abs((float) $data['qty']);

        DB::transaction(function () use ($product, $warehouse, $type, $absQty, $isPlus, $data) {
            // Lock per-warehouse inventory untuk consistent cost_avg read.
            // Pola identik StockOpnameController::complete baris 254-272.
            $inv = Inventory::query()
                ->withoutGlobalScopes()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->first();

            // Cost per-warehouse (BUKAN Product.cost_avg global — itu bug lama).
            // Kalau belum ada row inventory (produk baru di warehouse ini),
            // fallback ke Product.cost_avg. Sama dengan SO fallback.
            $costAvg = $inv ? (float) $inv->cost_avg : (float) ($product->cost_avg ?? 0);
            $amount = round($absQty * $costAvg, 2);

            $movement = $this->stock->record(
                product: $product,
                warehouse: $warehouse,
                type: $type,
                qty: $absQty,
                cost: $costAvg,
                options: [
                    'ref_type' => 'manual_adjustment',
                    'reason' => $data['reason'],
                    'notes' => $data['notes'] ?? null,
                    'allow_minus' => true, // manual adjust authoritative
                ],
            );

            // Skip jurnal kalau amount = 0 (cost_avg=0, produk baru tanpa pembelian).
            // Stok tetap berubah untuk tracking, jurnal di-skip sesuai semantics
            // JournalEngine::post (zero lines skipped). Tidak silent — operator
            // tahu dari Kartu Stok cost=0.
            if ($amount > 0) {
                $this->journal->postAdjustment(
                    ref: "ADJ-{$movement->id}",
                    amount: $amount,
                    isPlus: $isPlus,
                );
            }
        });

        $direction = $isPlus ? 'penambahan' : 'pengurangan';

        return back()->with('success',
            "Penyesuaian {$direction} stok '{$product->name}' tersimpan.");
    }
}
