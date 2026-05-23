<?php

use App\Http\Controllers\Purchasing\PurchaseRequestController;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseRequest;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

function callPrController(string $method, ?Request $request = null, ?PurchaseRequest $pr = null)
{
    $controller = new PurchaseRequestController;
    $request ??= Request::create('/purchasing/requests', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($request),
        'store' => $controller->store($request),
        'submit' => $controller->submit($request, $pr),
        'approve' => $controller->approve($request, $pr),
        'reject' => $controller->reject($request, $pr),
    };
}

function userByRole(string $role): TenantUser
{
    return TenantUser::query()
        ->whereHas('roles', fn ($q) => $q->where('name', $role))
        ->firstOrFail();
}

function makeDraftPr(int $requesterId, int $warehouseId, int $productId): PurchaseRequest
{
    $pr = PurchaseRequest::create([
        'pr_no' => 'PR-TEST-'.uniqid(),
        'requester_id' => $requesterId,
        'warehouse_id' => $warehouseId,
        'status' => PurchaseRequest::STATUS_DRAFT,
    ]);
    $pr->items()->create([
        'product_id' => $productId,
        'qty' => 5,
        'satuan' => 'pcs',
        'alasan' => 'stok habis',
    ]);

    return $pr;
}

beforeEach(function () {
    // Sync permissions ke test tenant (idempotent — handle DB lama).
    (new DefaultRolesSeeder)->run();

    // Provision cashier user kalau belum ada — DemoSeeder cuma bikin owner + apoteker.
    if (! TenantUser::query()->whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->exists()) {
        $cashier = TenantUser::create([
            'name' => 'Test Cashier',
            'email' => 'test-cashier@vetly.id',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ]);
        $cashier->assignRole('cashier');
    }
    if (! TenantUser::query()->whereHas('roles', fn ($q) => $q->where('name', 'manager'))->exists()) {
        $manager = TenantUser::create([
            'name' => 'Test Manager',
            'email' => 'test-manager@vetly.id',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => null,
        ]);
        $manager->assignRole('manager');
    }

    // Clear PRs dari test sebelumnya (cascade delete items via FK).
    PurchaseRequest::query()->where('pr_no', 'like', 'PR-TEST-%')->delete();
    PurchaseRequest::query()->where('pr_no', 'like', 'PR-2026%')->delete();
});

it('creates PR draft with items', function () {
    $apoteker = userByRole('apoteker');
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    Auth::login($apoteker);

    $request = Request::create('/purchasing/requests', 'POST', [
        'warehouse_id' => $warehouse->id,
        'notes' => 'restock obat',
        'items' => [
            ['product_id' => $product->id, 'qty' => 10, 'satuan' => 'pcs', 'alasan' => 'habis'],
            ['product_id' => $product->id, 'qty' => 5, 'satuan' => 'box', 'alasan' => null],
        ],
    ]);

    callPrController('store', $request);

    $pr = PurchaseRequest::where('requester_id', $apoteker->id)->latest('id')->first();
    expect($pr)->not->toBeNull()
        ->and($pr->status)->toBe('draft')
        ->and($pr->warehouse_id)->toBe($warehouse->id)
        ->and($pr->notes)->toBe('restock obat')
        ->and($pr->items)->toHaveCount(2)
        ->and((float) $pr->items[0]->qty)->toBe(10.0)
        ->and($pr->items[0]->satuan)->toBe('pcs');
});

it('generates PR number with PR-YYYYMM- prefix', function () {
    $apoteker = userByRole('apoteker');
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    Auth::login($apoteker);

    $request = Request::create('/purchasing/requests', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [['product_id' => $product->id, 'qty' => 1, 'satuan' => 'pcs']],
    ]);
    callPrController('store', $request);

    $pr = PurchaseRequest::where('requester_id', $apoteker->id)->latest('id')->first();
    expect($pr->pr_no)->toMatch('/^PR-\d{6}-\d{4}$/');
});

it('submits draft → submitted by requester', function () {
    $apoteker = userByRole('apoteker');
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();
    Auth::login($apoteker);

    $pr = makeDraftPr($apoteker->id, $warehouse->id, $product->id);

    callPrController('submit', pr: $pr);

    expect($pr->refresh()->status)->toBe('submitted');
});

it('manager approves submitted PR', function () {
    $apoteker = userByRole('apoteker');
    $manager = userByRole('manager');
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();

    $pr = makeDraftPr($apoteker->id, $warehouse->id, $product->id);
    $pr->update(['status' => 'submitted']);

    Auth::login($manager);
    callPrController('approve', pr: $pr);

    $pr->refresh();
    expect($pr->status)->toBe('approved')
        ->and($pr->approved_by)->toBe($manager->id)
        ->and($pr->approved_at)->not->toBeNull();
});

it('manager rejects submitted PR with reason', function () {
    $apoteker = userByRole('apoteker');
    $manager = userByRole('manager');
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();

    $pr = makeDraftPr($apoteker->id, $warehouse->id, $product->id);
    $pr->update(['status' => 'submitted']);

    Auth::login($manager);
    $request = Request::create("/purchasing/requests/{$pr->id}/reject", 'POST', [
        'rejected_reason' => 'Budget belum tersedia bulan ini',
    ]);
    callPrController('reject', $request, $pr);

    $pr->refresh();
    expect($pr->status)->toBe('rejected')
        ->and($pr->rejected_reason)->toBe('Budget belum tersedia bulan ini')
        ->and($pr->approved_by)->toBe($manager->id);
});

it('denies approve to users without purchasing.pr_approve', function () {
    $apoteker = userByRole('apoteker');
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();

    $pr = makeDraftPr($apoteker->id, $warehouse->id, $product->id);
    $pr->update(['status' => 'submitted']);

    Auth::login($apoteker);
    expect(fn () => callPrController('approve', pr: $pr))
        ->toThrow(AuthorizationException::class);
});

it('denies create to users without purchasing.pr_create', function () {
    // super_user role tidak punya pr_create (POS escalation only).
    $superUser = TenantUser::query()
        ->whereHas('roles', fn ($q) => $q->where('name', 'super_user'))
        ->first();

    // Bikin sementara kalau belum ada di test tenant.
    if (! $superUser) {
        $superUser = TenantUser::create([
            'name' => 'Test SuperUser',
            'email' => 'test-superuser@vetly.id',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ]);
        $superUser->assignRole('super_user');
    }

    Auth::login($superUser);
    $request = Request::create('/purchasing/requests', 'POST', [
        'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        'items' => [['product_id' => Product::query()->firstOrFail()->id, 'qty' => 1, 'satuan' => 'pcs']],
    ]);

    expect(fn () => callPrController('store', $request))
        ->toThrow(AuthorizationException::class);
});

it('cannot approve PR still in draft (status guard)', function () {
    $apoteker = userByRole('apoteker');
    $manager = userByRole('manager');
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();

    $pr = makeDraftPr($apoteker->id, $warehouse->id, $product->id);
    // sengaja tidak submit — masih draft.

    Auth::login($manager);
    expect(fn () => callPrController('approve', pr: $pr))
        ->toThrow(HttpException::class);
});

it('non-requester cannot submit someone elses draft', function () {
    $apoteker = userByRole('apoteker');
    $cashier = userByRole('cashier');
    $warehouse = Warehouse::query()->firstOrFail();
    $product = Product::query()->firstOrFail();

    $pr = makeDraftPr($apoteker->id, $warehouse->id, $product->id);

    Auth::login($cashier);
    expect(fn () => callPrController('submit', pr: $pr))
        ->toThrow(AuthorizationException::class);

    expect($pr->refresh()->status)->toBe('draft');
});
