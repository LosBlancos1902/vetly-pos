<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user->can('purchasing.po_create') && ! $user->can('purchasing.po_approve')) {
            throw new AuthorizationException('Tidak punya akses ke Purchase Order.');
        }

        $orders = PurchaseOrder::query()
            ->with([
                'supplier:id,code,name,payment_term_days',
                'warehouse:id,code,name',
                'creator:id,name',
                'approver:id,name',
                'items',
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn ($q, $sid) => $q->where('supplier_id', $sid))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Purchasing/PurchaseOrders', [
            'orders' => $orders,
            'filters' => $request->only('status', 'supplier_id'),
            'suppliers' => Supplier::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name', 'payment_term_days']),
            'warehouses' => Warehouse::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name']),
            'units' => MasterUnit::orderBy('code')->get(['id', 'code', 'name']),
            'defaultWarehouseId' => $user->warehouse_id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('purchasing.po_create');

        $data = $this->validatePo($request);

        DB::transaction(function () use ($data, $request) {
            $items = $data['items'];
            $subtotal = collect($items)->sum(fn ($i) => $i['qty_ordered'] * $i['unit_price']);

            $po = PurchaseOrder::create([
                'po_no' => $this->generatePoNo(),
                'pr_id' => $data['pr_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'payment_type' => $data['payment_type'],
                'payment_term_days' => $data['payment_type'] === PurchaseOrder::PAYMENT_TEMPO
                    ? $data['payment_term_days']
                    : 0,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($items as $row) {
                $po->items()->create([
                    'product_id' => $row['product_id'],
                    'unit_id' => $row['unit_id'],
                    'qty_ordered' => $row['qty_ordered'],
                    'qty_received' => 0,
                    'unit_price' => $row['unit_price'],
                    'subtotal' => $row['qty_ordered'] * $row['unit_price'],
                ]);
            }
        });

        return back()->with('success', 'Purchase Order dibuat sebagai draft.');
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('purchasing.po_create');

        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            abort(422, 'PO yang sudah disubmit tidak bisa diedit.');
        }

        $data = $this->validatePo($request);

        DB::transaction(function () use ($purchaseOrder, $data) {
            $items = $data['items'];
            $subtotal = collect($items)->sum(fn ($i) => $i['qty_ordered'] * $i['unit_price']);

            $purchaseOrder->update([
                'pr_id' => $data['pr_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'payment_type' => $data['payment_type'],
                'payment_term_days' => $data['payment_type'] === PurchaseOrder::PAYMENT_TEMPO
                    ? $data['payment_term_days']
                    : 0,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'notes' => $data['notes'] ?? null,
            ]);

            // Replace items wholesale — volume kecil, diffing rumit untuk gain minimal.
            $purchaseOrder->items()->delete();
            foreach ($items as $row) {
                $purchaseOrder->items()->create([
                    'product_id' => $row['product_id'],
                    'unit_id' => $row['unit_id'],
                    'qty_ordered' => $row['qty_ordered'],
                    'qty_received' => 0,
                    'unit_price' => $row['unit_price'],
                    'subtotal' => $row['qty_ordered'] * $row['unit_price'],
                ]);
            }
        });

        return back()->with('success', 'PO diperbarui.');
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('purchasing.po_create');

        if ($purchaseOrder->created_by !== $request->user()->id) {
            throw new AuthorizationException('Hanya pembuat PO yang bisa submit.');
        }

        if ($purchaseOrder->status !== PurchaseOrder::STATUS_DRAFT) {
            abort(422, "PO sudah '{$purchaseOrder->status}', tidak bisa submit ulang.");
        }

        $purchaseOrder->update(['status' => PurchaseOrder::STATUS_SUBMITTED]);

        return back()->with('success', "PO {$purchaseOrder->po_no} disubmit.");
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('purchasing.po_approve');

        if ($purchaseOrder->status !== PurchaseOrder::STATUS_SUBMITTED) {
            abort(422, "PO berstatus '{$purchaseOrder->status}', cuma PO submitted yang bisa diapprove.");
        }

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return back()->with('success', "PO {$purchaseOrder->po_no} disetujui.");
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->authorize('purchasing.po_approve');

        if ($purchaseOrder->status !== PurchaseOrder::STATUS_SUBMITTED) {
            abort(422, "PO berstatus '{$purchaseOrder->status}', cuma PO submitted yang bisa direject.");
        }

        $data = $request->validate([
            'rejected_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_REJECTED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejected_reason' => $data['rejected_reason'],
        ]);

        return back()->with('success', "PO {$purchaseOrder->po_no} ditolak.");
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $user = $request->user();
        if (! $user->can('purchasing.po_create') && ! $user->can('purchasing.po_approve')) {
            throw new AuthorizationException('Tidak punya akses untuk membatalkan PO.');
        }

        $cancellable = [
            PurchaseOrder::STATUS_DRAFT,
            PurchaseOrder::STATUS_SUBMITTED,
            PurchaseOrder::STATUS_APPROVED,
        ];
        if (! in_array($purchaseOrder->status, $cancellable, true)) {
            abort(422, "PO berstatus '{$purchaseOrder->status}' tidak bisa dibatalkan.");
        }

        $data = $request->validate([
            'cancelled_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $purchaseOrder->update([
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_reason' => $data['cancelled_reason'],
        ]);

        return back()->with('success', "PO {$purchaseOrder->po_no} dibatalkan.");
    }

    private function validatePo(Request $request): array
    {
        $data = $request->validate([
            'pr_id' => ['nullable', 'integer', 'exists:purchase_requests,id'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'payment_type' => ['required', 'in:cash,tempo'],
            'payment_term_days' => ['required_if:payment_type,tempo', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.unit_id' => ['required', 'integer', 'exists:master_units,id'],
            'items.*.qty_ordered' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        if ($data['payment_type'] === PurchaseOrder::PAYMENT_TEMPO
            && (int) ($data['payment_term_days'] ?? 0) <= 0) {
            abort(422, 'Payment tempo harus memiliki payment_term_days > 0.');
        }

        return $data;
    }

    /**
     * Format PO-YYYYMM-NNNN. Sequence per bulan, lockForUpdate untuk hindari race.
     */
    private function generatePoNo(): string
    {
        $prefix = 'PO-'.now()->format('Ym').'-';
        $lastSeq = PurchaseOrder::where('po_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->value('po_no');

        $next = $lastSeq ? ((int) substr($lastSeq, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
