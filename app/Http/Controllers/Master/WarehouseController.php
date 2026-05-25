<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master gudang (warehouse / outlet).
 *
 * Delete strategy: tolak hard-delete kalau gudang masih punya inventory
 * (qty != 0), stock movement, ATAU user fixed ke sana. Owner harus
 * "kosongkan dulu / pindah staff dulu" — tidak ada cascade silent.
 *
 * Default flag: minimal 1 warehouse harus is_default=true (POS pakai default
 * sebagai fallback). Toggle is_default=true unset semua yg lain dalam satu
 * transaction.
 */
class WarehouseController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('master.manage');

        $warehouses = Warehouse::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        // Hitung SKU + total qty + jumlah user yang ke-pin per warehouse.
        $ids = $warehouses->pluck('id')->all();
        $skuCounts = Inventory::query()
            ->whereIn('warehouse_id', $ids)
            ->where('qty', '>', 0)
            ->selectRaw('warehouse_id, COUNT(DISTINCT product_id) as cnt')
            ->groupBy('warehouse_id')
            ->pluck('cnt', 'warehouse_id');

        $userCounts = TenantUser::query()
            ->whereIn('warehouse_id', $ids)
            ->selectRaw('warehouse_id, COUNT(*) as cnt')
            ->groupBy('warehouse_id')
            ->pluck('cnt', 'warehouse_id');

        $warehouses->getCollection()->transform(function ($w) use ($skuCounts, $userCounts) {
            $w->sku_count = (int) ($skuCounts[$w->id] ?? 0);
            $w->user_count = (int) ($userCounts[$w->id] ?? 0);

            return $w;
        });

        return Inertia::render('Master/Warehouses', [
            'warehouses' => $warehouses,
            'warehouseTypes' => [
                Warehouse::TYPE_PETSHOP => 'Petshop',
                Warehouse::TYPE_KLINIK => 'Klinik',
                Warehouse::TYPE_APOTEK_KLINIK => 'Apotek Klinik',
                Warehouse::TYPE_GUDANG => 'Gudang',
            ],
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('master.manage');

        $data = $this->validateWarehouse($request);

        DB::transaction(function () use ($data) {
            $warehouse = Warehouse::create($data);
            $this->ensureSingleDefault($warehouse);
        });

        return back()->with('success', "Gudang '{$data['name']}' ditambahkan.");
    }

    public function update(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('master.manage');

        $data = $this->validateWarehouse($request, $warehouse->id);

        // Guard: nonaktif tidak boleh kalau ini satu-satunya default aktif,
        // atau kalau ini default yang lagi di-flag.
        $willDeactivate = isset($data['is_active']) && ! $data['is_active'];
        if ($willDeactivate) {
            $isDefault = $warehouse->is_default || ($data['is_default'] ?? false);
            if ($isDefault) {
                $otherDefault = Warehouse::where('id', '!=', $warehouse->id)
                    ->where('is_default', true)
                    ->where('is_active', true)
                    ->exists();
                if (! $otherDefault) {
                    abort(422, 'Tidak bisa menonaktifkan gudang default — set default ke gudang aktif lain dulu.');
                }
            }
        }

        DB::transaction(function () use ($warehouse, $data) {
            $warehouse->update($data);
            $this->ensureSingleDefault($warehouse);
        });

        return back()->with('success', "Gudang '{$warehouse->name}' diperbarui.");
    }

    public function destroy(Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('master.manage');

        // Jangan biarkan tenant tanpa default aktif sama sekali.
        if ($warehouse->is_default) {
            $otherDefault = Warehouse::where('id', '!=', $warehouse->id)
                ->where('is_default', true)
                ->where('is_active', true)
                ->exists();
            if (! $otherDefault) {
                abort(422, 'Tidak bisa menghapus gudang default — set default ke gudang aktif lain dulu.');
            }
        }

        // Cek dependency. Stock movement adalah ledger histori — sekali tercatat
        // tidak boleh hilang. Inventory qty > 0 = stok riil. User ke-pin = staff
        // yang masih aktif di outlet ini.
        $hasMovements = StockMovementModel::query()->withoutGlobalScopes()
            ->where('warehouse_id', $warehouse->id)->exists();
        $hasInventory = Inventory::query()->withoutGlobalScopes()
            ->where('warehouse_id', $warehouse->id)
            ->where('qty', '!=', 0)
            ->exists();
        $hasUsers = TenantUser::where('warehouse_id', $warehouse->id)->exists();

        if ($hasMovements || $hasInventory || $hasUsers) {
            $warehouse->update(['is_active' => false]);

            $reason = match (true) {
                $hasMovements => 'ada riwayat mutasi stok',
                $hasInventory => 'masih ada stok',
                default => 'masih ada user ter-pin',
            };

            return back()->with('success',
                "Gudang '{$warehouse->name}' dinonaktifkan ({$reason}, tidak bisa dihapus).");
        }

        // Aman: hapus inventory rows yg semuanya qty=0 supaya unique constraint
        // (product_id, warehouse_id) bersih kalau besok ada gudang baru re-use slot.
        Inventory::query()->withoutGlobalScopes()
            ->where('warehouse_id', $warehouse->id)->delete();
        $warehouse->delete();

        return back()->with('success', "Gudang '{$warehouse->name}' dihapus.");
    }

    private function validateWarehouse(Request $request, ?int $warehouseId = null): array
    {
        $codeRule = $warehouseId
            ? ['required', 'string', 'max:32', Rule::unique('warehouses', 'code')->ignore($warehouseId)]
            : ['required', 'string', 'max:32', 'unique:warehouses,code'];

        return $request->validate([
            'code' => $codeRule,
            'name' => ['required', 'string', 'max:120'],
            'warehouse_type' => ['required', Rule::in([
                Warehouse::TYPE_PETSHOP, Warehouse::TYPE_KLINIK,
                Warehouse::TYPE_APOTEK_KLINIK, Warehouse::TYPE_GUDANG,
            ])],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ]);
    }

    /**
     * Kalau warehouse ini di-flag is_default=true, unset yang lain.
     * Single-default invariant ditegakkan di tier app (bukan DB unique partial,
     * supaya MariaDB tetap portable).
     */
    private function ensureSingleDefault(Warehouse $warehouse): void
    {
        if (! $warehouse->is_default) {
            return;
        }
        Warehouse::where('id', '!=', $warehouse->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
