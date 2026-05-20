<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Sale;
use Inertia\Inertia;
use Inertia\Response;

class SaleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Sales/Index', [
            'sales' => Sale::with(['customer:id,name', 'warehouse:id,name'])
                ->latest('date')
                ->paginate(25),
        ]);
    }

    public function show(Sale $sale): Response
    {
        return Inertia::render('Sales/Detail', [
            'sale' => $sale->load(['items.product:id,name,sku', 'payments', 'customer', 'warehouse']),
        ]);
    }
}
