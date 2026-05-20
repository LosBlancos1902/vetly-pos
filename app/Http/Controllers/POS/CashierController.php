<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Product;
use App\Models\Tenant\Sale;
use App\Models\Tenant\Warehouse;
use App\Services\JournalEngine;
use App\Services\ReceiptPrinter;
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
        ]);
    }

    public function scan(string $barcode, Request $request, StockGuard $guard): JsonResponse
    {
        $product = Product::where('barcode', $barcode)
            ->orWhere('sku', $barcode)
            ->with('units.unit')
            ->first();

        if (! $product) {
            return response()->json(['found' => false], 404);
        }

        $warehouseId = (int) $request->integer('warehouse_id');
        $check = $guard->canSell($product->id, $warehouseId, 1, $request->user());

        return response()->json([
            'found' => true,
            'product' => $product,
            'stock' => $check,
        ]);
    }

    public function store(Request $request, StockMovement $stock, JournalEngine $journal, VetlySyncService $vetly): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.method' => ['required', 'string'],
            'payments.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        $sale = DB::transaction(function () use ($data, $request, $warehouse, $stock, $journal) {
            $subtotal = 0;
            $discount = 0;
            foreach ($data['items'] as $i) {
                $subtotal += $i['qty'] * $i['price'];
                $discount += $i['discount_amount'] ?? 0;
            }
            $total = $subtotal - $discount;

            $sale = Sale::create([
                'invoice_no' => 'INV-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'date' => now(),
                'warehouse_id' => $warehouse->id,
                'cashier_id' => $request->user()->id,
                'customer_id' => $data['customer_id'] ?? null,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => 0,
                'total' => $total,
                'payment_status' => 'paid',
                'status' => 'completed',
            ]);

            foreach ($data['items'] as $i) {
                $product = Product::findOrFail($i['product_id']);
                $sale->items()->create([
                    'product_id' => $product->id,
                    'unit_id' => $i['unit_id'],
                    'qty' => $i['qty'],
                    'price' => $i['price'],
                    'discount_amount' => $i['discount_amount'] ?? 0,
                    'cost_snapshot' => $product->cost_avg,
                    'subtotal' => $i['qty'] * $i['price'] - ($i['discount_amount'] ?? 0),
                ]);

                $stock->record($product, $warehouse, 'sale', (float) $i['qty'], (float) $product->cost_avg, [
                    'ref_type' => Sale::class,
                    'ref_id' => $sale->id,
                    'notes' => "Penjualan {$sale->invoice_no}",
                ]);
            }

            foreach ($data['payments'] as $p) {
                $sale->payments()->create([
                    'method' => $p['method'],
                    'amount' => $p['amount'],
                    'reference_no' => $p['reference_no'] ?? null,
                    'paid_at' => now(),
                ]);
            }

            $journal->postSale($sale->load('items'));

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

        $printer = new ReceiptPrinter();

        return response()->json([
            'sale' => $sale->load(['items', 'payments']),
            'escpos_payload_58mm' => base64_encode($printer->render($sale, '58mm')),
            'escpos_payload_80mm' => base64_encode($printer->render($sale, '80mm')),
        ]);
    }

    public function receipt(Sale $sale, Request $request, ReceiptPrinter $printer): JsonResponse
    {
        $width = $request->get('width', '58mm');

        return response()->json([
            'escpos_payload' => base64_encode($printer->render($sale, $width === '80mm' ? '80mm' : '58mm')),
        ]);
    }
}
