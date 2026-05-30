<?php

use App\Http\Controllers\Sales\SaleController;
use App\Models\Tenant\BrandingSettings;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Product;
use App\Models\Tenant\Promo;
use App\Models\Tenant\PromoApplication;
use App\Models\Tenant\Sale;
use App\Models\Tenant\SaleItem;
use App\Models\Tenant\SalePayment;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Struk Penjualan — READ-ONLY view.
 *
 * Test fokus:
 *   - Receipt props complete (items, promo, customer/umum, payment)
 *   - Angka MATCH sale.* (no recompute)
 *   - Permission: pos.access + warehouse scope (cashier WH lain → 403)
 *   - Width param: 58mm/80mm/default 80mm
 *
 * Cleanup: hapus sale prefix RECEIPT-*.
 */

function ownerForReceipt(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierAtWh(int $warehouseId): TenantUser
{
    $email = 'cashier-receipt-wh'.$warehouseId.'@test.local';
    $u = TenantUser::firstOrCreate(
        ['email' => $email],
        [
            'name' => 'Cashier Receipt WH'.$warehouseId,
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => $warehouseId,
        ],
    );
    $u->update(['warehouse_id' => $warehouseId]);
    if (! $u->hasRole('cashier')) {
        $u->assignRole('cashier');
    }

    return $u->fresh();
}

function userWithoutPosAccess(): TenantUser
{
    $u = TenantUser::firstOrCreate(
        ['email' => 'noaccess-receipt@test.local'],
        [
            'name' => 'No Access Receipt',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ],
    );
    // Apoteker punya pos.access. Pakai role super_user lalu sync ke ZERO permissions?
    // Lebih mudah: detach semua role + tidak assign. User tanpa role = no permissions.
    $u->syncRoles([]);

    return $u->fresh();
}

function buildSale(Warehouse $wh, ?Customer $customer = null, ?TenantUser $cashier = null): Sale
{
    $cashier ??= ownerForReceipt();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail();

    $sale = Sale::create([
        'invoice_no' => 'RECEIPT-'.uniqid('', true),
        'date' => '2027-10-15 14:30:00',
        'warehouse_id' => $wh->id,
        'cashier_id' => $cashier->id,
        'customer_id' => $customer?->id,
        'subtotal' => 50000,
        'discount_amount' => 0,
        'promo_discount_amount' => 5000,
        'tax_amount' => 0,
        'total' => 45000,
        'amount_paid' => 50000,
        'change_amount' => 5000,
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'status' => 'completed',
    ]);

    SaleItem::create([
        'sale_id' => $sale->id,
        'product_id' => $p1->id,
        'unit_id' => $p1->base_unit_id,
        'qty' => 2,
        'price' => 15000,
        'discount_amount' => 0,
        'cost_snapshot' => 5000,
        'subtotal' => 30000,
    ]);
    SaleItem::create([
        'sale_id' => $sale->id,
        'product_id' => $p2->id,
        'unit_id' => $p2->base_unit_id,
        'qty' => 1,
        'price' => 20000,
        'discount_amount' => 0,
        'cost_snapshot' => 8000,
        'subtotal' => 20000,
    ]);

    SalePayment::create([
        'sale_id' => $sale->id,
        'method' => 'cash',
        'amount' => 45000,
        'paid_at' => now(),
    ]);

    return $sale->load('items', 'payments');
}

function callReceipt(Sale $sale, array $query = [])
{
    $controller = app(SaleController::class);
    $req = Request::create('/sales/'.$sale->id.'/receipt', 'GET', $query);
    $req->setUserResolver(fn () => Auth::user());

    return $controller->receipt($req, $sale);
}

function cleanupReceiptSales(): void
{
    $ids = Sale::where('invoice_no', 'like', 'RECEIPT-%')->pluck('id');
    PromoApplication::whereIn('sale_id', $ids)->delete();
    SalePayment::whereIn('sale_id', $ids)->delete();
    SaleItem::whereIn('sale_id', $ids)->delete();
    Sale::whereIn('id', $ids)->delete();
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForReceipt());
    cleanupReceiptSales();
    Promo::query()->delete();
    BrandingSettings::query()->delete();
});

afterEach(function () {
    cleanupReceiptSales();
    Promo::query()->delete();
    BrandingSettings::query()->delete();
});

// ─── RENDER ─────────────────────────────────────────────────────────

it('RECEIPT: render dgn data lengkap (items, customer, payment, warehouse, cashier)', function () {
    $w = Warehouse::firstOrFail();
    $cust = Customer::firstOrCreate(
        ['code' => 'CUS-RECEIPT-001'],
        ['name' => 'Receipt Test Customer', 'is_active' => true],
    );
    $sale = buildSale($w, $cust);

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['sale']['invoice_no'])->toBe($sale->invoice_no);
    expect($props['sale']['customer']['name'])->toBe('Receipt Test Customer');
    expect($props['sale']['warehouse']['id'])->toBe($w->id);
    expect($props['sale']['warehouse']['name'])->toBe($w->name);
    expect($props['sale']['cashier']['name'])->toBe(ownerForReceipt()->name);
    expect($props['sale']['items'])->toHaveCount(2);
    expect($props['sale']['payments'])->toHaveCount(1);
});

it('RECEIPT: customer null → tampilkan "Umum" (FE handle)', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w, null); // no customer

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['sale']['customer'])->toBeNull();
});

// ─── DATA KONSISTENSI (no recompute) ─────────────────────────────────

it('KONSISTENSI: angka di props PERSIS sale.* (no recompute)', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w);

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    // Server output decimal sebagai string ('50000.00') — controller TIDAK
    // ubah jadi recompute. Bandingkan apa adanya.
    expect((float) $props['sale']['subtotal'])->toBe(50000.0);
    expect((float) $props['sale']['promo_discount_amount'])->toBe(5000.0);
    expect((float) $props['sale']['total'])->toBe(45000.0);
    expect((float) $props['sale']['amount_paid'])->toBe(50000.0);
    expect((float) $props['sale']['change_amount'])->toBe(5000.0);
});

// ─── PROMO APPLICATIONS LOADED ──────────────────────────────────────

it('PROMO: receipt load promoApplications dgn promo.name', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w);

    // Buat 1 promo + application
    $promo = Promo::create([
        'name' => 'Promo Receipt Test',
        'type' => Promo::TYPE_PERIODE,
        'discount_kind' => 'percent',
        'discount_value' => 10,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    PromoApplication::create([
        'promo_id' => $promo->id,
        'sale_id' => $sale->id,
        'discount_amount' => 5000,
        'coa_code' => '4199',
        'applied_at' => now(),
    ]);

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['sale']['promo_applications'])->toHaveCount(1);
    expect($props['sale']['promo_applications'][0]['promo']['name'])->toBe('Promo Receipt Test');
    expect((float) $props['sale']['promo_applications'][0]['discount_amount'])->toBe(5000.0);
});

// ─── WIDTH PARAM ────────────────────────────────────────────────────

it('WIDTH: default = 80mm', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w);

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['width'])->toBe('80mm');
});

it('WIDTH: ?width=58mm → 58mm', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w);

    $props = callReceipt($sale, ['width' => '58mm'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['width'])->toBe('58mm');
});

it('WIDTH: invalid value fallback ke 80mm', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w);

    $props = callReceipt($sale, ['width' => 'evil-xss-attempt'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['width'])->toBe('80mm');
});

// ─── PERMISSION ─────────────────────────────────────────────────────

it('PERM: user tanpa pos.access → AuthorizationException', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w);

    Auth::login(userWithoutPosAccess());

    expect(fn () => callReceipt($sale))->toThrow(AuthorizationException::class);
});

it('PERM: cashier fixed-to-WH lain tidak boleh akses struk WH-A → 403', function () {
    $whA = Warehouse::firstOrCreate(
        ['code' => 'WH-RECEIPT-A'],
        ['name' => 'Receipt WH-A', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    $whB = Warehouse::firstOrCreate(
        ['code' => 'WH-RECEIPT-B'],
        ['name' => 'Receipt WH-B', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );

    $sale = buildSale($whA);

    Auth::login(cashierAtWh($whB->id));

    expect(fn () => callReceipt($sale))->toThrow(AccessDeniedHttpException::class);
});

it('PERM: cashier fixed-to-WH-A boleh akses struk dari WH-A → OK', function () {
    $whA = Warehouse::firstOrCreate(
        ['code' => 'WH-RECEIPT-OK'],
        ['name' => 'Receipt WH OK', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );

    $sale = buildSale($whA);
    Auth::login(cashierAtWh($whA->id));

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];
    expect($props['sale']['invoice_no'])->toBe($sale->invoice_no);
});

it('PERM: owner (warehouse_id=null) boleh akses semua warehouse', function () {
    $whA = Warehouse::firstOrCreate(
        ['code' => 'WH-RECEIPT-OWNER'],
        ['name' => 'Receipt WH Owner', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );

    $sale = buildSale($whA);
    Auth::login(ownerForReceipt()); // warehouse_id = null

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];
    expect($props['sale']['invoice_no'])->toBe($sale->invoice_no);
});

// ─── VOID STATUS ────────────────────────────────────────────────────

it('VOID: sale.status void diteruskan ke props (FE tampilkan banner VOID)', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w);
    $sale->update(['status' => 'void', 'voided_at' => now(), 'void_reason' => 'test']);

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];
    expect($props['sale']['status'])->toBe('void');
});

// ─── BRANDING ──────────────────────────────────────────────────────

it('BRANDING: props always include branding key (null fallback OK)', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w);

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props)->toHaveKey('branding');
    expect($props['branding'])->toHaveKeys(['brand_name', 'logo_data', 'footer_text', 'npwp', 'license_no']);
    expect($props['branding']['brand_name'])->toBeNull();
});

it('BRANDING: tenant branding diset → ke-pass ke props receipt', function () {
    $w = Warehouse::firstOrFail();
    BrandingSettings::singleton()->update([
        'brand_name' => 'My Brand',
        'footer_text' => 'Footer brand',
        'npwp' => '01.123.456.7-001.000',
    ]);

    $sale = buildSale($w);

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['branding']['brand_name'])->toBe('My Brand');
    expect($props['branding']['footer_text'])->toBe('Footer brand');
    expect($props['branding']['npwp'])->toBe('01.123.456.7-001.000');
});

it('BRANDING: warehouse phone + footer_override ke-pass via props sale.warehouse', function () {
    $wh = Warehouse::firstOrCreate(
        ['code' => 'WH-RECEIPT-BR'],
        ['name' => 'WH Brand', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => 'Jl. A'],
    );
    $wh->update(['phone' => '021-7777', 'footer_override' => 'Cabang ini override']);

    $sale = buildSale($wh);

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['sale']['warehouse']['phone'])->toBe('021-7777');
    expect($props['sale']['warehouse']['footer_override'])->toBe('Cabang ini override');
});

it('BRANDING: data transaksi (sale.*) tidak berubah saat branding diupdate', function () {
    $w = Warehouse::firstOrFail();
    $sale = buildSale($w);
    $totalBefore = (string) $sale->total;
    $subtotalBefore = (string) $sale->subtotal;

    BrandingSettings::singleton()->update(['brand_name' => 'Brand Baru', 'footer_text' => 'Footer baru']);

    $props = callReceipt($sale)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    // sale.* tetap utuh — branding hanya tampilan.
    expect((string) $props['sale']['total'])->toBe($totalBefore);
    expect((string) $props['sale']['subtotal'])->toBe($subtotalBefore);
});
