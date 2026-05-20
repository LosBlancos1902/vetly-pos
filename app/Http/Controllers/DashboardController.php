<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Product;
use App\Models\Tenant\Sale;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $today = now()->toDateString();

        return Inertia::render('Dashboard', [
            'stats' => [
                'sales_today_count' => Sale::whereDate('date', $today)->where('status', 'completed')->count(),
                'sales_today_total' => (float) Sale::whereDate('date', $today)->where('status', 'completed')->sum('total'),
                'product_count' => Product::count(),
                'low_stock' => DB::table('inventories')
                    ->join('products', 'products.id', '=', 'inventories.product_id')
                    ->whereColumn('inventories.qty', '<=', 'products.min_stock')
                    ->count(),
            ],
            'tenant' => tenant() ? ['id' => tenant('id')] : null,
        ]);
    }
}
