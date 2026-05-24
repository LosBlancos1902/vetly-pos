<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\PendingStockMovement;
use App\Models\Tenant\PriceTier;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductUnit;
use App\Models\Tenant\Sale;
use App\Models\Tenant\ServiceBundle;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\Warehouse;
use App\Services\JournalEngine;
use App\Services\Promo\PromoContext;
use App\Services\Promo\PromoResolver;
use App\Services\ReceiptPrinter;
use App\Services\ServiceBundleService;
use App\Services\StockGuard;
use App\Services\StockMovement;
use App\Services\VetlySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CashierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('POS/Cashier', [
            'warehouses' => Warehouse::where('is_active', true)->get(['id', 'code', 'name']),
            // Tier list dipakai untuk dropdown header "Harga". Frontend
            // auto-select tier default. Tier inaktif tetap dikirim supaya
            // bisa muncul kalau owner sengaja aktifkan ulang mid-shift.
            'tiers' => PriceTier::orderBy('sort_order')
                ->get(['id', 'name', 'sort_order', 'is_default', 'is_active']),
        ]);
    }

    public function scan(string $barcode, Request $request, StockGuard $guard): JsonResponse
    {
        $product = Product::where('barcode', $barcode)
            ->orWhere('sku', $barcode)
            ->orWhereHas('units', fn ($q) => $q->where('barcode_per_unit', $barcode))
            ->with(['units.unit', 'units.prices'])
            ->first();

        if (! $product) {
            return response()->json(['found' => false], 404);
        }

        $warehouseId = (int) $request->integer('warehouse_id');
        $check = $guard->canSell($product->id, $warehouseId, 1, null, $request->user());

        // Append units[] dgn resolved prices per tier (fallback F2 sudah
        // di-apply server-side, frontend cukup lookup `prices[tierId]`).
        $productArr = $product->toArray();
        $productArr['units'] = $this->resolveUnitsWithPrices($product->units);

        return response()->json([
            'found' => true,
            'product' => $productArr,
            'stock' => $check,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $warehouseId = (int) $request->integer('warehouse_id');

        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $products = Product::query()
            ->where('is_active', true)
            ->where('is_sellable_directly', true)
            ->where('type', '!=', Product::TYPE_RAW_MATERIAL)
            ->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            })
            ->with(['units.unit', 'units.prices'])
            ->orderBy('name')
            ->limit(20)
            ->get();

        if ($products->isEmpty()) {
            return response()->json(['results' => []]);
        }

        $serviceTypes = [Product::TYPE_SERVICE, Product::TYPE_SERVICE_WITH_CONSUMPTION];
        $stockableIds = $products->reject(fn ($p) => in_array($p->type, $serviceTypes, true))
            ->pluck('id')->all();

        $inventory = $stockableIds && $warehouseId
            ? Inventory::query()
                ->withoutGlobalScopes()
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $stockableIds)
                ->pluck('qty', 'product_id')
            : collect();

        $results = $products->map(function ($p) use ($inventory, $serviceTypes) {
            $isService = in_array($p->type, $serviceTypes, true);

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'name' => $p->name,
                'type' => $p->type,
                'price' => (float) $p->price,
                'base_unit_id' => $p->base_unit_id,
                'stock_qty' => $isService ? null : (float) ($inventory[$p->id] ?? 0),
                'is_service' => $isService,
                'units' => $this->resolveUnitsWithPrices($p->units),
            ];
        });

        return response()->json(['results' => $results]);
    }

    /**
     * Preview endpoint — resolve promo applicable utk cart snapshot
     * SAAT INI tanpa nulis ke DB. Dipanggil dari Cashier.tsx live
     * (debounced) supaya kasir lihat real-time diskon yg apply
     * sebelum klik BAYAR.
     */
    public function promoPreview(Request $request, PromoResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'customer_id' => ['nullable', 'integer'],
            'voucher_code' => ['nullable', 'string', 'max:32'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $subtotal = 0.0;
        $manualDiscount = 0.0;
        foreach ($data['items'] as $i) {
            $subtotal += (float) $i['qty'] * (float) $i['price'];
            $manualDiscount += (float) ($i['discount_amount'] ?? 0);
        }

        $voucherCode = ! empty($data['voucher_code'])
            ? strtoupper(trim($data['voucher_code']))
            : null;

        $result = $resolver->resolve(new PromoContext(
            items: $data['items'],
            warehouse: $warehouse,
            customerId: $data['customer_id'] ?? null,
            datetime: now(),
            subtotal: $subtotal,
            manualDiscount: $manualDiscount,
            voucherCode: $voucherCode,
        ));

        return response()->json([
            'total_discount' => $result->totalDiscount,
            'applied' => array_map(fn ($a) => [
                'id' => $a->promo->id,
                'name' => $a->promo->name,
                'amount' => $a->amount,
                'coa_code' => $a->coaCode,
            ], $result->applied),
        ]);
    }

    public function store(
        Request $request,
        StockMovement $stock,
        JournalEngine $journal,
        ServiceBundleService $serviceBundles,
        VetlySyncService $vetly,
        ?PromoResolver $promoResolver = null,
    ): JsonResponse {
        // Lazy-resolve untuk backward-compat dgn existing test helpers
        // yg panggil store() dgn 5 arg (sebelum promo engine ada).
        // Laravel DI tetap auto-inject saat dipanggil via routing.
        $promoResolver ??= app(PromoResolver::class);

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            // price_tier_id audit-only: harga ground truth tetap dari
            // items.*.price (customer bayar apa yg tampil di invoice).
            // Server tidak re-validate vs tier accessor — kasir bisa
            // override price/diskon manual.
            'price_tier_id' => ['nullable', 'integer', 'exists:price_tiers,id'],
            // Tipe 3 voucher — kasir input kode dari customer (optional)
            'voucher_code' => ['nullable', 'string', 'max:32'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            // F1 payment flow — split SKIP, single payment per sale.
            // Backward-compat: payments[] tetap diterima (sales_payments
            // table jadi source of truth). Field flat di sales = denorm
            // convenience.
            'payment_method' => ['nullable', 'string', 'in:cash,transfer,qris'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['nullable', 'array'],
            'payments.*.method' => ['required_with:payments', 'string'],
            'payments.*.amount' => ['required_with:payments', 'numeric', 'min:0'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        // ── ANTI-TAMPER: hitung total dari items SEBELUM transaction.
        // Server tidak pernah baca 'total' dari client. Validasi cash
        // jalan pakai computed total — bukan amount_paid client.
        $computedSubtotal = 0.0;
        $computedDiscount = 0.0;
        foreach ($data['items'] as $i) {
            $computedSubtotal += (float) $i['qty'] * (float) $i['price'];
            $computedDiscount += (float) ($i['discount_amount'] ?? 0);
        }
        $totalBeforePromo = $computedSubtotal - $computedDiscount;

        // ── PROMO RESOLVE (server-side authoritative, anti-tamper) ───
        // Client tidak boleh kirim discount_amount; resolver yg nentu.
        // Quota habis di tengah race → strategi: tetap apply di sini,
        // tapi di transaction loop lockForUpdate + re-check (kalau gugur,
        // skip + flag di response).
        $voucherCodeStore = ! empty($data['voucher_code'])
            ? strtoupper(trim($data['voucher_code']))
            : null;

        $promoResult = $promoResolver->resolve(new PromoContext(
            items: $data['items'],
            warehouse: $warehouse,
            customerId: $data['customer_id'] ?? null,
            datetime: now(),
            subtotal: $computedSubtotal,
            manualDiscount: $computedDiscount,
            voucherCode: $voucherCodeStore,
        ));
        $promoDiscountInitial = $promoResult->totalDiscount;

        $computedTotal = $totalBeforePromo - $promoDiscountInitial;

        // Resolve payment fields (prefer flat, fallback ke payments[0] untuk
        // backward compat existing test/client).
        $paymentMethod = $data['payment_method']
            ?? ($data['payments'][0]['method'] ?? null);
        $amountPaid = isset($data['amount_paid'])
            ? (float) $data['amount_paid']
            : ((float) ($data['payments'][0]['amount'] ?? $computedTotal));

        // Normalize untuk validation (legacy methods di-map ke 3 modern).
        if ($paymentMethod !== null && ! in_array($paymentMethod, ['cash', 'transfer', 'qris'], true)) {
            // Legacy method (debit/credit/ewallet/voucher) — treat as non-cash.
            $effectiveMethod = 'transfer';
        } else {
            $effectiveMethod = $paymentMethod ?? 'cash';
        }

        if ($effectiveMethod === 'cash') {
            if ($amountPaid + 0.001 < $computedTotal) {
                abort(422, 'Uang diterima kurang dari total.');
            }
        } else {
            // Transfer/QRIS — amount_paid harus exact (toleransi rounding 0.01)
            if (abs($amountPaid - $computedTotal) > 0.01) {
                abort(422, 'Untuk transfer/QRIS, uang diterima harus = total.');
            }
        }

        $changeAmount = max(0, $amountPaid - $computedTotal);

        // Akan diisi di transaction kalau ada promo yg gugur di race.
        $skippedPromos = [];

        $sale = DB::transaction(function () use (
            $data, $request, $warehouse, $stock, $journal, $serviceBundles,
            $computedSubtotal, $computedDiscount, $computedTotal,
            $paymentMethod, $amountPaid, $changeAmount,
            $promoResult, &$skippedPromos,
        ) {
            // SO-frozen context: map [product_id => opname_id] untuk warehouse ini.
            // Kalau kosong (no active SO) → semua flow normal, zero deviation.
            // Kalau ada → produk yang lagi di-snap akan defer-ke-pending.
            $frozenContext = StockOpname::frozenContextFor($warehouse->id);

            // ── PROMO commit-time re-check + quota lock ──────────────────
            // Race-safe: lockForUpdate tiap promo, re-check quota.
            // Promo yg gugur → skip (sale tetap jalan + warning di response).
            $appliedFinal = [];
            $promoDiscountFinal = 0.0;
            foreach ($promoResult->applied as $a) {
                $locked = \App\Models\Tenant\Promo::lockForUpdate()->find($a->promo->id);
                if ($locked === null
                    || ! $locked->is_active
                    || ! $locked->hasQuotaLeft()
                ) {
                    $skippedPromos[] = ['id' => $a->promo->id, 'name' => $a->promo->name];

                    continue;
                }
                $appliedFinal[] = $a;
                $promoDiscountFinal += $a->amount;
                $locked->increment('quota_used');
            }
            $promoDiscountFinal = round($promoDiscountFinal, 2);

            // Total mungkin berubah kalau ada promo gugur — adjust sebelum
            // create sale row supaya konsisten.
            $totalAdjusted = $computedSubtotal - $computedDiscount - $promoDiscountFinal;
            $changeAdjusted = max(0, $amountPaid - $totalAdjusted);

            // Alias supaya rest of function tidak berubah (avoid diff besar)
            $subtotal = $computedSubtotal;
            $discount = $computedDiscount;
            $total = $totalAdjusted;

            $sale = Sale::create([
                'invoice_no' => 'INV-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'date' => now(),
                'warehouse_id' => $warehouse->id,
                'cashier_id' => $request->user()->id,
                'customer_id' => $data['customer_id'] ?? null,
                'price_tier_id' => $data['price_tier_id'] ?? null,
                'payment_method' => in_array($paymentMethod, ['cash', 'transfer', 'qris'], true)
                    ? $paymentMethod
                    : null, // legacy 7-enum method ditolak masuk kolom flat (denorm cuma 3)
                'amount_paid' => $amountPaid,
                'change_amount' => $changeAdjusted, // adjusted setelah promo gugur
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'promo_discount_amount' => $promoDiscountFinal,
                'tax_amount' => 0,
                'total' => $total,
                'payment_status' => 'paid',
                'status' => 'completed',
            ]);

            // Insert audit row utk promo yg ke-apply.
            foreach ($appliedFinal as $a) {
                \App\Models\Tenant\PromoApplication::create([
                    'promo_id' => $a->promo->id,
                    'sale_id' => $sale->id,
                    'discount_amount' => $a->amount,
                    'coa_code' => $a->coaCode,
                    'applied_at' => now(),
                ]);
            }

            // Aggregate per-portion totals so we can split the journal by
            // revenue stream (retail vs jasa).
            $retailSubtotal = 0.0;
            $retailDiscount = 0.0;
            $retailCogs = 0.0;
            $serviceSubtotal = 0.0;
            $serviceCogs = 0.0;

            foreach ($data['items'] as $i) {
                $product = Product::findOrFail($i['product_id']);
                $lineSubtotal = (float) $i['qty'] * (float) $i['price'] - (float) ($i['discount_amount'] ?? 0);

                $saleItem = $sale->items()->create([
                    'product_id' => $product->id,
                    'unit_id' => $i['unit_id'],
                    'qty' => $i['qty'],
                    'price' => $i['price'],
                    'discount_amount' => $i['discount_amount'] ?? 0,
                    'cost_snapshot' => $product->cost_avg,
                    'subtotal' => $lineSubtotal,
                ]);

                $isService = in_array($product->type, [Product::TYPE_SERVICE, Product::TYPE_SERVICE_WITH_CONSUMPTION], true);
                if ($isService) {
                    $serviceSubtotal += $lineSubtotal;

                    // Find the active bundle to consume components. A service
                    // product without a bundle is sellable as a flat-fee item.
                    $bundle = ServiceBundle::where('product_id', $product->id)
                        ->where('is_active', true)
                        ->latest('id')
                        ->first();

                    if ($bundle && $bundle->items()->exists()) {
                        $result = $serviceBundles->execute(
                            bundle: $bundle,
                            warehouse: $warehouse,
                            user: $request->user(),
                            optionalIncluded: null,
                            options: [
                                'ref_type' => Sale::class,
                                'ref_id' => $sale->id,
                                'sale_item_id' => $saleItem->id,
                                'notes' => "Penjualan {$sale->invoice_no} (jasa: {$bundle->name})",
                                'frozen_context' => $frozenContext,
                            ],
                        );
                        $serviceCogs += (float) $result['cost_total'];
                    }
                } else {
                    // Revenue side: ALWAYS akumulasi (customer bayar penuh termasuk frozen).
                    $retailSubtotal += (float) $i['qty'] * (float) $i['price'];
                    $retailDiscount += (float) ($i['discount_amount'] ?? 0);

                    // COGS + stock side: SKIP kalau frozen (ditahan ke pending).
                    if (isset($frozenContext[$product->id])) {
                        PendingStockMovement::create([
                            'opname_id' => $frozenContext[$product->id],
                            'sale_id' => $sale->id,
                            'sale_item_id' => $saleItem->id,
                            'product_id' => $product->id,
                            'warehouse_id' => $warehouse->id,
                            'type' => 'sale',
                            'qty_base' => (float) $i['qty'],
                            'cost_per_base' => (float) $product->cost_avg,
                            'notes' => "Penjualan {$sale->invoice_no} (pending: SO aktif)",
                            'created_at' => now(),
                        ]);
                    } else {
                        // Path normal — TIDAK BERUBAH dari sebelum upgrade.
                        $retailCogs += (float) $product->cost_avg * (float) $i['qty'];

                        $stock->record($product, $warehouse, 'sale', (float) $i['qty'], (float) $product->cost_avg, [
                            'ref_type' => Sale::class,
                            'ref_id' => $sale->id,
                            'notes' => "Penjualan {$sale->invoice_no}",
                        ]);
                    }
                }
            }

            // sales_payments tetap di-insert sebagai source of truth.
            // Kalau client kirim payments[] (legacy/test) → loop apa adanya.
            // Kalau cuma kirim flat (F1 modern client) → synthesize 1 row
            // dgn amount = total (bukan amount_paid, supaya rekap penerimaan
            // tetap = sales.total; kembalian = duit balik ke customer).
            if (! empty($data['payments'])) {
                foreach ($data['payments'] as $p) {
                    $sale->payments()->create([
                        'method' => $p['method'],
                        'amount' => $p['amount'],
                        'reference_no' => $p['reference_no'] ?? null,
                        'paid_at' => now(),
                    ]);
                }
            } else {
                $sale->payments()->create([
                    'method' => $paymentMethod ?? 'cash',
                    'amount' => $computedTotal, // penerimaan bersih, exclude change
                    'reference_no' => null,
                    'paid_at' => now(),
                ]);
            }

            // SELALU pakai postSplitSale (atau ...WithPromo) supaya retailCogs
            // eksplisit dipassing (excluding deferred items kalau ada SO aktif).
            // postSale auto-sum dari sale.items → bocor kalau ada deferred.
            //
            // FORK: kalau ada promo → method baru WithPromo (extension method,
            // existing postSplitSale 100% tidak disentuh). Kalau tanpa promo,
            // path lama dipakai → behavior byte-identical dgn pre-promo.
            if ($promoDiscountFinal > 0) {
                // COA code dari promo pertama; kalau >1 promo dgn COA berbeda,
                // pakai COA promo terbesar (heuristic — alternatif: split 1 baris
                // per COA, tapi balance proof lebih rumit; fase 1 simpel).
                $primaryCoa = collect($appliedFinal)
                    ->sortByDesc('amount')
                    ->first()
                    ->coaCode ?? '4199';

                $journal->postSplitSaleWithPromo(
                    sale: $sale->load('items'),
                    retailSubtotal: $retailSubtotal,
                    retailDiscount: $retailDiscount,
                    retailCogs: $retailCogs,
                    serviceSubtotal: $serviceSubtotal,
                    serviceCogs: $serviceCogs,
                    promoDiscount: $promoDiscountFinal,
                    promoCoaCode: $primaryCoa,
                );
            } else {
                $journal->postSplitSale(
                    sale: $sale->load('items'),
                    retailSubtotal: $retailSubtotal,
                    retailDiscount: $retailDiscount,
                    retailCogs: $retailCogs,
                    serviceSubtotal: $serviceSubtotal,
                    serviceCogs: $serviceCogs,
                );
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'sale.completed',
                'model_type' => Sale::class,
                'model_id' => $sale->id,
                'after' => $sale->toArray(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return $sale;
        });

        $vetly->pushSaleToVetly($sale);

        // F3: increment customer.total_spent (di luar transaction utama —
        // kalau cache update gagal/race, sale tetap valid; nanti detail
        // page akan auto-reconcile via SUM query. Drift kalau ada void/
        // refund di future = didocument utk fase later.)
        if ($sale->customer_id !== null) {
            try {
                \App\Models\Tenant\Customer::where('id', $sale->customer_id)
                    ->increment('total_spent', (float) $sale->total);
            } catch (\Throwable $e) {
                // Non-fatal — log saja, jangan throw.
                \Log::warning('Customer total_spent increment failed', [
                    'customer_id' => $sale->customer_id,
                    'sale_id' => $sale->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $printer = new ReceiptPrinter();

        return response()->json([
            'sale' => $sale->load(['items', 'payments']),
            'escpos_payload_58mm' => base64_encode($printer->render($sale, '58mm')),
            'escpos_payload_80mm' => base64_encode($printer->render($sale, '80mm')),
            // Flag utk frontend kalau ada promo yg gugur di race (kuota habis,
            // dst). UI tampil warning "promo X tidak ke-apply karena ..."
            'skipped_promos' => $skippedPromos,
        ]);
    }

    public function receipt(Sale $sale, Request $request, ReceiptPrinter $printer): JsonResponse
    {
        $width = $request->get('width', '58mm');

        return response()->json([
            'escpos_payload' => base64_encode($printer->render($sale, $width === '80mm' ? '80mm' : '58mm')),
        ]);
    }

    /**
     * Resolve harga per satuan per tier dgn fallback F2 sudah di-apply,
     * lalu serialize ke shape ringan untuk frontend POS:
     *   [{ id, unit_id, code, name, level, conversion_to_base,
     *      prices: {tier_id: number, ...} }, ...]
     *
     * Output `prices` SELALU lengkap (semua tier punya angka) — frontend
     * cukup lookup `prices[selectedTierId]` tanpa duplicate fallback logic.
     */
    private function resolveUnitsWithPrices($units): array
    {
        $tierIds = PriceTier::pluck('id')->all();

        return $units->map(function (ProductUnit $u) use ($tierIds) {
            $prices = [];
            foreach ($tierIds as $tid) {
                $prices[$tid] = $u->priceForTier((int) $tid);
            }

            return [
                'id' => $u->id,
                'unit_id' => $u->unit_id,
                'code' => $u->unit?->code,
                'name' => $u->unit?->name,
                'level' => $u->level,
                'conversion_to_base' => (float) $u->conversion_to_base,
                'is_sale_unit' => (bool) $u->is_sale_unit,
                'prices' => $prices,
            ];
        })->values()->all();
    }
}
