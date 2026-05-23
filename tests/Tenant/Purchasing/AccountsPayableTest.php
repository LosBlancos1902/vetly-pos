<?php

use App\Http\Controllers\Purchasing\AccountsPayableController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Models\Tenant\AccountsPayable;
use App\Models\Tenant\ApPayment;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Journal;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function callApController(string $method, ?Request $request = null, ?AccountsPayable $ap = null)
{
    $controller = app(AccountsPayableController::class);
    $request ??= Request::create('/purchasing/payables', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($request),
        'pay' => $controller->pay($request, $ap),
    };
}

function ownerForAp(): TenantUser
{
    return TenantUser::query()
        ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
        ->firstOrFail();
}

/**
 * Bikin PO approved + receive sekaligus — supaya AP otomatis kebuat untuk
 * skenario tempo.
 */
function makeAndReceivePo(string $paymentType, int $termDays, float $unitPrice, float $qty): array
{
    $supplier = Supplier::firstOrCreate(
        ['code' => 'AP-TEST-SUP'],
        ['name' => 'AP Test Supplier', 'payment_term_days' => 0, 'is_active' => true],
    );
    $product = Product::where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();

    // Reset inventory.
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
        ['qty' => 0, 'cost_avg' => 0, 'updated_at' => now(), 'created_at' => now()],
    );

    $subtotal = $qty * $unitPrice;
    $po = PurchaseOrder::create([
        'po_no' => 'PO-AP-'.uniqid(),
        'supplier_id' => $supplier->id,
        'warehouse_id' => $warehouse->id,
        'payment_type' => $paymentType,
        'payment_term_days' => $termDays,
        'status' => 'approved',
        'subtotal' => $subtotal,
        'total' => $subtotal,
        'created_by' => Auth::id() ?? ownerForAp()->id,
        'approved_by' => Auth::id() ?? ownerForAp()->id,
        'approved_at' => now(),
    ]);
    $poItem = $po->items()->create([
        'product_id' => $product->id,
        'unit_id' => $product->base_unit_id,
        'qty_ordered' => $qty,
        'qty_received' => 0,
        'unit_price' => $unitPrice,
        'subtotal' => $subtotal,
    ]);

    // Receive via controller — supaya AP auto-created.
    $controller = app(GoodsReceiptController::class);
    $req = Request::create('/purchasing/receipts', 'POST', [
        'po_id' => $po->id,
        'received_at' => now()->toDateString(),
        'items' => [['po_item_id' => $poItem->id, 'unit_id' => $product->base_unit_id, 'qty_received' => $qty]],
    ]);
    $req->setUserResolver(fn () => Auth::user());
    $controller->store($req);

    return [
        'po' => $po->refresh(),
        'gr' => GoodsReceipt::latest('id')->first(),
        'supplier' => $supplier,
    ];
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();

    ApPayment::query()->delete();
    AccountsPayable::query()->delete();
    GoodsReceipt::query()->delete();
    PurchaseOrder::query()->delete();
    StockMovement::query()->withoutGlobalScopes()
        ->whereIn('ref_type', [GoodsReceipt::class])->delete();
    Journal::whereIn('ref_type', ['purchase', 'ap_payment'])->delete();
    Supplier::query()->where('code', 'like', 'AP-TEST-%')->delete();

    Auth::login(ownerForAp());
});

it('AP kecatat saat tempo PO di-receive (amount + due_date correct)', function () {
    // Tempo 30 hari, 10 pcs @ 1000 = 10000.
    $bundle = makeAndReceivePo('tempo', 30, 1000, 10);

    $ap = AccountsPayable::where('gr_id', $bundle['gr']->id)->first();
    expect($ap)->not->toBeNull()
        ->and((float) $ap->amount)->toBe(10000.0)
        ->and((float) $ap->paid_amount)->toBe(0.0)
        ->and($ap->status)->toBe('open')
        ->and($ap->supplier_id)->toBe($bundle['supplier']->id)
        ->and($ap->due_date->toDateString())->toBe(now()->copy()->addDays(30)->toDateString())
        ->and($ap->journal_id)->toBe($bundle['gr']->journal_id);
});

it('AP TIDAK kecatat untuk cash PO', function () {
    $bundle = makeAndReceivePo('cash', 0, 1000, 10);

    $ap = AccountsPayable::where('gr_id', $bundle['gr']->id)->first();
    expect($ap)->toBeNull();
});

it('full payment → status=paid + remaining=0', function () {
    $bundle = makeAndReceivePo('tempo', 30, 1000, 10);
    $ap = AccountsPayable::where('gr_id', $bundle['gr']->id)->firstOrFail();

    $req = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 10000,
        'payment_coa_code' => '1101',
        'paid_at' => now()->toDateString(),
    ]);
    callApController('pay', $req, $ap);

    $ap->refresh();
    expect($ap->status)->toBe('paid')
        ->and((float) $ap->paid_amount)->toBe(10000.0)
        ->and((float) $ap->remaining_amount)->toBe(0.0);
});

it('partial payment → status=partially_paid + remaining>0', function () {
    $bundle = makeAndReceivePo('tempo', 30, 1000, 10);
    $ap = AccountsPayable::where('gr_id', $bundle['gr']->id)->firstOrFail();

    $req = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 4000,
        'payment_coa_code' => '1101',
        'paid_at' => now()->toDateString(),
    ]);
    callApController('pay', $req, $ap);

    $ap->refresh();
    expect($ap->status)->toBe('partially_paid')
        ->and((float) $ap->paid_amount)->toBe(4000.0)
        ->and((float) $ap->remaining_amount)->toBe(6000.0);
});

it('multiple partial payments akumulasi → paid saat sum >= amount', function () {
    $bundle = makeAndReceivePo('tempo', 30, 1000, 10);
    $ap = AccountsPayable::where('gr_id', $bundle['gr']->id)->firstOrFail();

    // 1st: 3000
    $r1 = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 3000, 'payment_coa_code' => '1101', 'paid_at' => now()->toDateString(),
    ]);
    callApController('pay', $r1, $ap);
    expect($ap->refresh()->status)->toBe('partially_paid');

    // 2nd: 3000
    $r2 = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 3000, 'payment_coa_code' => '1101', 'paid_at' => now()->toDateString(),
    ]);
    callApController('pay', $r2, $ap);
    expect($ap->refresh()->status)->toBe('partially_paid')
        ->and((float) $ap->paid_amount)->toBe(6000.0);

    // 3rd: 4000 → lunas
    $r3 = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 4000, 'payment_coa_code' => '1101', 'paid_at' => now()->toDateString(),
    ]);
    callApController('pay', $r3, $ap);
    $ap->refresh();
    expect($ap->status)->toBe('paid')
        ->and((float) $ap->paid_amount)->toBe(10000.0)
        ->and($ap->payments)->toHaveCount(3);
});

it('payment journal: D 2101 / C 1101 (atau COA yang dipilih) exact', function () {
    $bundle = makeAndReceivePo('tempo', 30, 1000, 10);
    $ap = AccountsPayable::where('gr_id', $bundle['gr']->id)->firstOrFail();

    // Bayar via Bank BCA (1103) = 7000.
    $req = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 7000,
        'payment_coa_code' => '1103',
        'paid_at' => now()->toDateString(),
    ]);
    callApController('pay', $req, $ap);

    $payment = ApPayment::latest('id')->with('journal.entries.coa')->first();
    expect($payment->journal)->not->toBeNull();

    $byCoa = $payment->journal->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);

    expect($byCoa)->toHaveKeys(['2101', '1103'])
        ->and($byCoa['2101']['debit'])->toBe(7000.0)
        ->and($byCoa['2101']['credit'])->toBe(0.0)
        ->and($byCoa['1103']['debit'])->toBe(0.0)
        ->and($byCoa['1103']['credit'])->toBe(7000.0);
});

it('tolak overpay (amount > sisa hutang)', function () {
    $bundle = makeAndReceivePo('tempo', 30, 1000, 10);
    $ap = AccountsPayable::where('gr_id', $bundle['gr']->id)->firstOrFail();

    // Total = 10000. Bayar 4000 dulu → sisa 6000.
    $r1 = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 4000, 'payment_coa_code' => '1101', 'paid_at' => now()->toDateString(),
    ]);
    callApController('pay', $r1, $ap);

    // Coba bayar 7000 (> sisa 6000) → harus ditolak.
    $r2 = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 7000, 'payment_coa_code' => '1101', 'paid_at' => now()->toDateString(),
    ]);
    expect(fn () => callApController('pay', $r2, $ap->refresh()))
        ->toThrow(HttpException::class);
});

it('tolak pay AP yang sudah paid', function () {
    $bundle = makeAndReceivePo('tempo', 30, 1000, 10);
    $ap = AccountsPayable::where('gr_id', $bundle['gr']->id)->firstOrFail();

    // Lunasi.
    $r1 = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 10000, 'payment_coa_code' => '1101', 'paid_at' => now()->toDateString(),
    ]);
    callApController('pay', $r1, $ap);

    // Coba bayar lagi → tolak.
    $r2 = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 100, 'payment_coa_code' => '1101', 'paid_at' => now()->toDateString(),
    ]);
    expect(fn () => callApController('pay', $r2, $ap->refresh()))
        ->toThrow(HttpException::class);
});

it('user tanpa purchasing.ap_view ditolak akses list', function () {
    $manager = TenantUser::firstOrCreate(['email' => 'ap-mgr@vetly.id'], [
        'name' => 'AP Manager',
        'password' => bcrypt('test'),
        'is_active' => true,
    ]);
    $manager->syncRoles(['manager']);
    Role::findByName('manager')->revokePermissionTo(['purchasing.ap_view', 'purchasing.ap_pay']);

    Auth::login($manager);
    expect(fn () => callApController('index'))->toThrow(AuthorizationException::class);
});

it('user tanpa purchasing.ap_pay ditolak bayar (walau bisa view)', function () {
    $bundle = makeAndReceivePo('tempo', 30, 1000, 10);
    $ap = AccountsPayable::where('gr_id', $bundle['gr']->id)->firstOrFail();

    $manager = TenantUser::firstOrCreate(['email' => 'ap-mgr@vetly.id'], [
        'name' => 'AP Manager',
        'password' => bcrypt('test'),
        'is_active' => true,
    ]);
    $manager->syncRoles(['manager']);
    Role::findByName('manager')->givePermissionTo('purchasing.ap_view');
    Role::findByName('manager')->revokePermissionTo('purchasing.ap_pay');

    Auth::login($manager);
    $req = Request::create("/purchasing/payables/{$ap->id}/pay", 'POST', [
        'amount' => 1000, 'payment_coa_code' => '1101', 'paid_at' => now()->toDateString(),
    ]);
    expect(fn () => callApController('pay', $req, $ap))
        ->toThrow(AuthorizationException::class);
});
