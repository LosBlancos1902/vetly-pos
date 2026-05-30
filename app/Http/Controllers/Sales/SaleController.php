<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Tenant\BrandingSettings;
use App\Models\Tenant\Sale;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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

    /**
     * Struk Penjualan — view thermal-ready (READ-ONLY).
     *
     * Permission:
     *   - pos.access (basic gate)
     *   - kalau user fixed-to-WH (cashier/staff) → sale.warehouse_id WAJIB
     *     sama dgn user.warehouse_id (anti-bypass lihat struk cabang lain).
     *
     * Tidak ada hitung ulang — semua nilai diambil apa adanya dari sale.*
     * (subtotal, discount_amount, promo_discount_amount, total, amount_paid,
     * change_amount). Jurnal/HPP/StockMovement TIDAK disentuh.
     */
    public function receipt(Request $request, Sale $sale): Response
    {
        $this->authorize('pos.access');

        $user = $request->user();
        if ($user->warehouse_id !== null && (int) $sale->warehouse_id !== (int) $user->warehouse_id) {
            throw new AccessDeniedHttpException(
                'Tidak boleh akses struk dari cabang lain.',
            );
        }

        $sale->load([
            'items.product:id,name,sku',
            'items.unit:id,code,name',
            'payments',
            'customer:id,code,name',
            'warehouse:id,code,name,address,phone,footer_override,warehouse_type',
            'cashier:id,name',
            'promoApplications.promo:id,name,type',
        ]);

        $width = $request->get('width') === '58mm' ? '58mm' : '80mm';

        // Branding terbaru (tdk historis per-transaksi — keputusan: simplicity).
        // Singleton row dibuat on-the-fly kalau belum ada.
        $b = BrandingSettings::singleton();

        return Inertia::render('Sales/Receipt', [
            'sale' => $sale,
            'width' => $width,
            'tenantName' => tenant() ? (string) tenant('id') : 'VETLY POS',
            'branding' => [
                'brand_name' => $b->brand_name,
                'logo_data' => $b->logo_data,
                'footer_text' => $b->footer_text,
                'npwp' => $b->npwp,
                'license_no' => $b->license_no,
            ],
            'printedAt' => now()->toIso8601String(),
        ]);
    }
}
