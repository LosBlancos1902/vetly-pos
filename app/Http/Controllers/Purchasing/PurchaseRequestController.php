<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PurchaseRequest;
use App\Models\Tenant\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        if (! $user->can('purchasing.pr_create') && ! $user->can('purchasing.pr_approve')) {
            throw new AuthorizationException('Tidak punya akses ke Purchase Request.');
        }

        $query = PurchaseRequest::query()
            ->with([
                'requester:id,name',
                'warehouse:id,code,name',
                'approver:id,name',
                'items',
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('id');

        $requests = $query->paginate(20)->withQueryString();

        return Inertia::render('Purchasing/PurchaseRequests', [
            'requests' => $requests,
            'filters' => $request->only('status'),
            'warehouses' => Warehouse::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name']),
            'defaultWarehouseId' => $user->warehouse_id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('purchasing.pr_create');

        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.satuan' => ['required', 'string', 'max:64'],
            'items.*.alasan' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $request) {
            $pr = PurchaseRequest::create([
                'pr_no' => $this->generatePrNo(),
                'requester_id' => $request->user()->id,
                'warehouse_id' => $data['warehouse_id'],
                'status' => PurchaseRequest::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $row) {
                $pr->items()->create([
                    'product_id' => $row['product_id'],
                    'qty' => $row['qty'],
                    'satuan' => $row['satuan'],
                    'alasan' => $row['alasan'] ?? null,
                ]);
            }
        });

        return back()->with('success', 'Purchase Request dibuat sebagai draft.');
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('purchasing.pr_create');

        if ($purchaseRequest->requester_id !== $request->user()->id) {
            throw new AuthorizationException('Hanya pembuat PR yang bisa submit.');
        }

        if ($purchaseRequest->status !== PurchaseRequest::STATUS_DRAFT) {
            abort(422, "PR sudah '{$purchaseRequest->status}', tidak bisa submit ulang.");
        }

        $purchaseRequest->update(['status' => PurchaseRequest::STATUS_SUBMITTED]);

        return back()->with('success', "PR {$purchaseRequest->pr_no} disubmit.");
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('purchasing.pr_approve');

        if ($purchaseRequest->status !== PurchaseRequest::STATUS_SUBMITTED) {
            abort(422, "PR berstatus '{$purchaseRequest->status}', cuma PR submitted yang bisa diapprove.");
        }

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_APPROVED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return back()->with('success', "PR {$purchaseRequest->pr_no} disetujui.");
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('purchasing.pr_approve');

        if ($purchaseRequest->status !== PurchaseRequest::STATUS_SUBMITTED) {
            abort(422, "PR berstatus '{$purchaseRequest->status}', cuma PR submitted yang bisa direject.");
        }

        $data = $request->validate([
            'rejected_reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $purchaseRequest->update([
            'status' => PurchaseRequest::STATUS_REJECTED,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejected_reason' => $data['rejected_reason'],
        ]);

        return back()->with('success', "PR {$purchaseRequest->pr_no} ditolak.");
    }

    /**
     * Format PR-YYYYMM-NNNN. Sequence di-scoped per bulan, hitung dari
     * PR yang dibuat di bulan ini + 1.
     */
    private function generatePrNo(): string
    {
        $prefix = 'PR-'.now()->format('Ym').'-';
        $lastSeq = PurchaseRequest::where('pr_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->value('pr_no');

        $next = $lastSeq ? ((int) substr($lastSeq, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
