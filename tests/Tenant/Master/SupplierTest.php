<?php

use App\Http\Controllers\Master\SupplierController;
use App\Models\Tenant\Supplier;
use App\Models\Tenant\User as TenantUser;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

function callSupplierController(string $method, ?Request $request = null, ?Supplier $supplier = null)
{
    $controller = new SupplierController;
    $request ??= Request::create('/master/suppliers', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'index' => $controller->index($request),
        'store' => $controller->store($request),
        'update' => $controller->update($request, $supplier),
        'destroy' => $controller->destroy($supplier),
    };
}

function ownerUser(): TenantUser
{
    return TenantUser::query()->whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function apotekerUser(): TenantUser
{
    return TenantUser::query()->whereHas('roles', fn ($q) => $q->where('name', 'apoteker'))->firstOrFail();
}

beforeEach(function () {
    // Sync permissions ke test tenant — DB provisioning lama mungkin belum punya
    // permission baru yang ditambah setelah pertama kali tenant diprovisioning.
    (new DefaultRolesSeeder)->run();

    // Bersihkan supplier yang kebuat dari test sebelumnya (tenant DB persist).
    Supplier::query()->where('code', 'like', 'TEST-%')->delete();
});

it('creates supplier via store', function () {
    Auth::login(ownerUser());

    $request = Request::create('/master/suppliers', 'POST', [
        'code' => 'TEST-001',
        'name' => 'Test Supplier A',
        'phone' => '08111',
        'email' => 'a@test.id',
        'address' => 'Jakarta',
        'npwp' => '00.000.000.0-000.000',
        'payment_term_days' => 30,
        'is_active' => true,
    ]);

    callSupplierController('store', $request);

    $created = Supplier::where('code', 'TEST-001')->first();
    expect($created)->not->toBeNull()
        ->and($created->name)->toBe('Test Supplier A')
        ->and($created->payment_term_days)->toBe(30)
        ->and($created->npwp)->toBe('00.000.000.0-000.000')
        ->and($created->is_active)->toBeTrue();
});

it('updates supplier via update', function () {
    Auth::login(ownerUser());
    $supplier = Supplier::create([
        'code' => 'TEST-002', 'name' => 'Old Name', 'payment_term_days' => 0, 'is_active' => true,
    ]);

    $request = Request::create("/master/suppliers/{$supplier->id}", 'PUT', [
        'code' => 'TEST-002',
        'name' => 'New Name',
        'phone' => '0822',
        'payment_term_days' => 14,
        'is_active' => true,
    ]);

    callSupplierController('update', $request, $supplier);

    $supplier->refresh();
    expect($supplier->name)->toBe('New Name')
        ->and($supplier->phone)->toBe('0822')
        ->and($supplier->payment_term_days)->toBe(14);
});

it('soft-deletes supplier by flipping is_active=false (NOT hard delete)', function () {
    Auth::login(ownerUser());
    $supplier = Supplier::create([
        'code' => 'TEST-003', 'name' => 'Soon Inactive', 'payment_term_days' => 0, 'is_active' => true,
    ]);
    $supplierId = $supplier->id;

    callSupplierController('destroy', supplier: $supplier);

    $still = Supplier::find($supplierId);
    expect($still)->not->toBeNull()
        ->and($still->is_active)->toBeFalse();
});

it('reactivates supplier via update setting is_active=true', function () {
    Auth::login(ownerUser());
    $supplier = Supplier::create([
        'code' => 'TEST-004', 'name' => 'Reactivate Me', 'payment_term_days' => 0, 'is_active' => false,
    ]);

    $request = Request::create("/master/suppliers/{$supplier->id}", 'PUT', [
        'code' => 'TEST-004',
        'name' => 'Reactivate Me',
        'payment_term_days' => 0,
        'is_active' => true,
    ]);

    callSupplierController('update', $request, $supplier);

    expect($supplier->refresh()->is_active)->toBeTrue();
});

it('searches suppliers by name, code, phone, email (index filter)', function () {
    Auth::login(ownerUser());
    Supplier::create(['code' => 'TEST-FINDME', 'name' => 'Findme Co', 'payment_term_days' => 0, 'is_active' => true]);
    Supplier::create(['code' => 'TEST-OTHER', 'name' => 'Other Co', 'payment_term_days' => 0, 'is_active' => true]);

    $request = Request::create('/master/suppliers', 'GET', ['search' => 'Findme']);
    /** @var \Inertia\Response $response */
    $response = callSupplierController('index', $request);

    $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];
    $codes = collect($props['suppliers']['data'])->pluck('code')->all();

    expect($codes)->toContain('TEST-FINDME')
        ->and($codes)->not->toContain('TEST-OTHER');
});

it('rejects duplicate code on store', function () {
    Auth::login(ownerUser());
    Supplier::create(['code' => 'TEST-DUP', 'name' => 'Existing', 'payment_term_days' => 0, 'is_active' => true]);

    $request = Request::create('/master/suppliers', 'POST', [
        'code' => 'TEST-DUP',
        'name' => 'Duplicate',
        'payment_term_days' => 0,
    ]);

    expect(fn () => callSupplierController('store', $request))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('denies access to users without purchasing.supplier_manage permission', function () {
    Auth::login(apotekerUser());

    $request = Request::create('/master/suppliers', 'GET');

    expect(fn () => callSupplierController('index', $request))
        ->toThrow(AuthorizationException::class);
});

it('denies store/update/destroy to apoteker', function () {
    Auth::login(apotekerUser());
    $supplier = Supplier::create([
        'code' => 'TEST-GATE', 'name' => 'Gated', 'payment_term_days' => 0, 'is_active' => true,
    ]);

    $storeReq = Request::create('/master/suppliers', 'POST', ['code' => 'TEST-X', 'name' => 'X', 'payment_term_days' => 0]);
    expect(fn () => callSupplierController('store', $storeReq))->toThrow(AuthorizationException::class);

    $updateReq = Request::create("/master/suppliers/{$supplier->id}", 'PUT', ['code' => 'TEST-GATE', 'name' => 'Y', 'payment_term_days' => 0]);
    expect(fn () => callSupplierController('update', $updateReq, $supplier))->toThrow(AuthorizationException::class);

    expect(fn () => callSupplierController('destroy', supplier: $supplier))->toThrow(AuthorizationException::class);
});
