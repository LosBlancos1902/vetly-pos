<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Promo;
use App\Models\Tenant\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD promo. Fase 1: cuma "Diskon Periode" (Promo::TYPE_PERIODE)
 * yg di-enable di form; 4 tipe lain ada di registry tapi UI grayed-out.
 */
class PromoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('promo.manage');

        $promos = Promo::query()
            ->with(['discountCoa:id,code,name', 'warehouses:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Master/Promos', [
            'promos' => $promos,
            // Owner pilih COA dari sini. Filter: type revenue dgn
            // normal_balance=debit (contra-revenue) ATAU type expense —
            // semantically valid utk pos diskon. Reject asset/liability.
            'coas' => Coa::query()
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where(function ($w) {
                        $w->where('type', 'revenue')->where('normal_balance', 'debit');
                    })->orWhere('type', 'expense');
                })
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
            'warehouses' => Warehouse::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name']),
            'filters' => $request->only('search', 'status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('promo.manage');

        $data = $this->validatePromo($request);

        DB::transaction(function () use ($data, $request) {
            $promo = Promo::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'discount_kind' => $data['discount_kind'],
                'discount_value' => $data['discount_value'],
                'max_discount_amount' => $data['max_discount_amount'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'days_of_week' => $data['days_of_week'] ?? null,
                'time_start' => $data['time_start'] ?? null,
                'time_end' => $data['time_end'] ?? null,
                'discount_coa_id' => $data['discount_coa_id'] ?? null,
                'min_purchase' => $data['min_purchase'] ?? 0,
                'min_qty' => $data['min_qty'] ?? 0,
                'quota_total' => $data['quota_total'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $request->user()->id,
            ]);

            if (! empty($data['warehouse_ids'])) {
                $promo->warehouses()->sync($data['warehouse_ids']);
            }
        });

        return back()->with('success', "Promo '{$data['name']}' ditambahkan.");
    }

    public function update(Request $request, Promo $promo): RedirectResponse
    {
        $this->authorize('promo.manage');

        $data = $this->validatePromo($request, $promo->id);

        DB::transaction(function () use ($data, $promo) {
            $promo->update([
                'name' => $data['name'],
                'type' => $data['type'],
                'discount_kind' => $data['discount_kind'],
                'discount_value' => $data['discount_value'],
                'max_discount_amount' => $data['max_discount_amount'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'days_of_week' => $data['days_of_week'] ?? null,
                'time_start' => $data['time_start'] ?? null,
                'time_end' => $data['time_end'] ?? null,
                'discount_coa_id' => $data['discount_coa_id'] ?? null,
                'min_purchase' => $data['min_purchase'] ?? 0,
                'min_qty' => $data['min_qty'] ?? 0,
                'quota_total' => $data['quota_total'] ?? null,
                'is_active' => $data['is_active'] ?? $promo->is_active,
            ]);

            // sync warehouses ([] = semua cabang)
            $promo->warehouses()->sync($data['warehouse_ids'] ?? []);
        });

        return back()->with('success', "Promo '{$promo->name}' diperbarui.");
    }

    public function destroy(Promo $promo): RedirectResponse
    {
        $this->authorize('promo.manage');

        // Kalau promo pernah dipakai, soft-deactivate utk jaga audit
        // trail di promo_applications (FK nullable, tapi name di histori
        // hilang kalau hard-delete).
        if ($promo->applications()->exists()) {
            $promo->update(['is_active' => false]);

            return back()->with('success', "Promo '{$promo->name}' dinonaktifkan (ada histori pemakaian).");
        }

        $promo->delete();

        return back()->with('success', "Promo '{$promo->name}' dihapus.");
    }

    private function validatePromo(Request $request, ?int $promoId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                Promo::TYPE_PERIODE,
                // 4 tipe lain di-disable di UI fase 1, tapi server tolerate
                // (validasi lebih strict di strategy yg return false utk stub).
                Promo::TYPE_PER_ITEM,
                Promo::TYPE_VOUCHER,
                Promo::TYPE_BUNDLING,
                Promo::TYPE_TEBUS_MURAH,
            ])],
            'discount_kind' => ['required', 'in:percent,nominal'],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
            'time_start' => ['nullable', 'date_format:H:i,H:i:s'],
            'time_end' => ['nullable', 'date_format:H:i,H:i:s'],
            'discount_coa_id' => ['nullable', 'integer', 'exists:coa,id'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'min_qty' => ['nullable', 'integer', 'min:0'],
            'quota_total' => ['nullable', 'integer', 'min:1'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:warehouses,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
