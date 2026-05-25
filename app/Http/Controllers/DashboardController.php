<?php

namespace App\Http\Controllers;

use App\Models\Tenant\AccountsPayable;
use App\Models\Tenant\Sale;
use App\Models\Tenant\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Read-only owner dashboard. Aggregates from existing sales / inventory /
     * AP tables — no writes, no schema changes.
     *
     * Warehouse scope:
     *   - user with warehouse_id (cashier/staff) → forced to own warehouse
     *   - user without warehouse_id + warehouse.view_all → dropdown filter
     *
     * Section permissions:
     *   - omzet / trx / chart / top produk: any authed user (cashier still
     *     lands here after login; they get their warehouse's view)
     *   - low-stock list: inventory.view
     *   - AP jatuh tempo list: purchasing.ap_view
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $canViewAll = $user->warehouse_id === null && $user->can('warehouse.view_all');

        $requestedWarehouseId = $canViewAll
            ? ($request->integer('warehouse_id') ?: null)
            : $user->warehouse_id;

        // Validate requested warehouse exists & is active when an owner picks one.
        if ($canViewAll && $requestedWarehouseId) {
            $exists = Warehouse::where('id', $requestedWarehouseId)->active()->exists();
            if (! $exists) {
                $requestedWarehouseId = null;
            }
        }

        $warehouses = $canViewAll
            ? Warehouse::active()->orderBy('name')->get(['id', 'name'])
            : collect();

        $today = CarbonImmutable::now()->startOfDay();
        $monthStart = CarbonImmutable::now()->startOfMonth();
        $trendStart = $today->subDays(29); // 30 hari termasuk hari ini

        $applyWarehouse = fn ($q, string $col = 'warehouse_id') => $requestedWarehouseId
            ? $q->where($col, $requestedWarehouseId)
            : $q;

        // --- KARTU AGREGASI ---
        $todayAgg = $applyWarehouse(Sale::query())
            ->where('status', 'completed')
            ->whereBetween('date', [$today, $today->endOfDay()])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total), 0) as total')
            ->first();

        $monthAgg = $applyWarehouse(Sale::query())
            ->where('status', 'completed')
            ->where('date', '>=', $monthStart)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total), 0) as total')
            ->first();

        $monthCount = (int) $monthAgg->cnt;
        $monthTotal = (float) $monthAgg->total;
        $aov = $monthCount > 0 ? $monthTotal / $monthCount : 0.0;

        // --- TREN 30 HARI (sum harian) ---
        $trendRows = $applyWarehouse(Sale::query())
            ->where('status', 'completed')
            ->where('date', '>=', $trendStart)
            ->selectRaw('DATE(date) as d, COALESCE(SUM(total), 0) as total')
            ->groupBy('d')
            ->orderBy('d')
            ->pluck('total', 'd');

        $trend = [];
        for ($i = 0; $i < 30; $i++) {
            $d = $trendStart->addDays($i)->toDateString();
            $trend[] = ['date' => $d, 'total' => (float) ($trendRows[$d] ?? 0)];
        }

        // --- TOP 5 PRODUK by OMZET bulan ini ---
        // Pilih omzet (bukan qty) karena cross-unit (vial vs pcs vs gram)
        // qty murni misleading buat owner; omzet bersifat universal.
        $topProducts = DB::table('sales_items')
            ->join('sales', 'sales.id', '=', 'sales_items.sale_id')
            ->join('products', 'products.id', '=', 'sales_items.product_id')
            ->where('sales.status', 'completed')
            ->where('sales.date', '>=', $monthStart)
            ->when(
                $requestedWarehouseId,
                fn ($q) => $q->where('sales.warehouse_id', $requestedWarehouseId)
            )
            ->groupBy('products.id', 'products.name', 'products.sku')
            ->selectRaw('products.id, products.name, products.sku, '
                .'SUM(sales_items.subtotal) as omzet, '
                .'SUM(sales_items.qty) as qty')
            ->orderByDesc('omzet')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'sku' => $r->sku,
                'omzet' => (float) $r->omzet,
                'qty' => (float) $r->qty,
            ])->values()->all();

        // --- ALERT: stok menipis ---
        $lowStock = [];
        if ($user->can('inventory.view')) {
            $lowStock = DB::table('inventories')
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->where('products.is_active', true)
                ->where('products.min_stock', '>', 0)
                ->whereColumn('inventories.qty', '<=', 'products.min_stock')
                ->when(
                    $requestedWarehouseId,
                    fn ($q) => $q->where('inventories.warehouse_id', $requestedWarehouseId)
                )
                ->orderByRaw('(inventories.qty - products.min_stock) ASC')
                ->limit(10)
                ->get([
                    'products.id as product_id',
                    'products.name as product_name',
                    'products.sku',
                    'warehouses.name as warehouse_name',
                    'inventories.qty',
                    'products.min_stock',
                ])
                ->map(fn ($r) => [
                    'product_id' => (int) $r->product_id,
                    'product_name' => $r->product_name,
                    'sku' => $r->sku,
                    'warehouse_name' => $r->warehouse_name,
                    'qty' => (float) $r->qty,
                    'min_stock' => (float) $r->min_stock,
                ])->values()->all();
        }

        // --- ALERT: AP jatuh tempo (≤7 hari ke depan + overdue) ---
        $apDue = [];
        if ($user->can('purchasing.ap_view')) {
            $horizon = $today->addDays(7);
            $apDue = AccountsPayable::with('supplier:id,name')
                ->whereIn('status', ['open', 'partially_paid'])
                ->where('due_date', '<=', $horizon)
                ->orderBy('due_date')
                ->limit(10)
                ->get()
                ->map(fn ($ap) => [
                    'id' => $ap->id,
                    'ap_no' => $ap->ap_no,
                    'supplier_name' => $ap->supplier?->name ?? '-',
                    'due_date' => $ap->due_date?->toDateString(),
                    'amount' => (float) $ap->amount,
                    'paid_amount' => (float) $ap->paid_amount,
                    'remaining' => (float) bcsub(
                        (string) $ap->amount,
                        (string) $ap->paid_amount,
                        2
                    ),
                    'is_overdue' => $ap->due_date
                        ? $ap->due_date->startOfDay()->lt($today)
                        : false,
                ])->values()->all();
        }

        return Inertia::render('Dashboard', [
            'filters' => [
                'warehouse_id' => $requestedWarehouseId,
                'can_view_all' => $canViewAll,
            ],
            'warehouses' => $warehouses,
            'stats' => [
                'today' => [
                    'total' => (float) $todayAgg->total,
                    'count' => (int) $todayAgg->cnt,
                ],
                'month' => [
                    'total' => $monthTotal,
                    'count' => $monthCount,
                    'aov' => $aov,
                ],
            ],
            'trend' => $trend,
            'top_products' => $topProducts,
            'low_stock' => $lowStock,
            'ap_due' => $apDue,
            'can' => [
                'view_inventory' => $user->can('inventory.view'),
                'view_ap' => $user->can('purchasing.ap_view'),
            ],
            'tenant' => tenant() ? ['id' => tenant('id')] : null,
        ]);
    }
}
