<?php

use App\Http\Controllers\POS\CashierController;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\StockGuard;
use App\Services\UnitConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

function callSearch(string $q, int $warehouseId): array
{
    $controller = new CashierController;
    $request = Request::create('/pos/products/search', 'GET', [
        'q' => $q,
        'warehouse_id' => $warehouseId,
    ]);
    $request->setUserResolver(fn () => Auth::user());

    $response = $controller->search($request);

    return json_decode($response->getContent(), true);
}

beforeEach(function () {
    Auth::login(TenantUser::query()->first());
});

it('returns service product matched by name fragment', function () {
    $warehouse = Warehouse::query()->firstOrFail();

    $body = callSearch('Vaksin', $warehouse->id);

    $names = collect($body['results'])->pluck('name')->all();
    expect($names)->toContain('Vaksin Rabies (jasa)');

    $svc = collect($body['results'])->firstWhere('sku', 'SVC-VAKRAB');
    expect($svc)->not->toBeNull()
        ->and($svc['is_service'])->toBeTrue()
        ->and($svc['type'])->toBe('service_with_consumption')
        ->and($svc['stock_qty'])->toBeNull();
});

it('matches by SKU substring', function () {
    $warehouse = Warehouse::query()->firstOrFail();

    $body = callSearch('VAKRAB', $warehouse->id);

    $skus = collect($body['results'])->pluck('sku')->all();
    expect($skus)->toContain('SVC-VAKRAB');
});

it('excludes raw_material products', function () {
    $warehouse = Warehouse::query()->firstOrFail();

    // RAW-VAKRAB (Vial Vaksin Rabies) is type=raw_material and would otherwise
    // match the same "VAKRAB" fragment as SVC-VAKRAB — it must be filtered out.
    $body = callSearch('VAKRAB', $warehouse->id);

    $skus = collect($body['results'])->pluck('sku')->all();
    expect($skus)->not->toContain('RAW-VAKRAB');
});

it('excludes inactive products', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $svc = Product::where('sku', 'SVC-VAKRAB')->firstOrFail();
    $svc->update(['is_active' => false]);

    try {
        $body = callSearch('Vaksin', $warehouse->id);
        $skus = collect($body['results'])->pluck('sku')->all();
        expect($skus)->not->toContain('SVC-VAKRAB');
    } finally {
        $svc->update(['is_active' => true]);
    }
});

it('returns empty for short query', function () {
    $warehouse = Warehouse::query()->firstOrFail();

    $body = callSearch('V', $warehouse->id);

    expect($body['results'])->toBe([]);
});

it('includes stock_qty for retail products from the requested warehouse', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $retail = Product::where('type', Product::TYPE_SALEABLE_RETAIL)
        ->where('is_active', true)->where('is_sellable_directly', true)
        ->firstOrFail();

    Inventory::query()->withoutGlobalScopes()
        ->updateOrInsert(
            ['product_id' => $retail->id, 'warehouse_id' => $warehouse->id],
            ['qty' => 42, 'cost_avg' => 1000],
        );

    $body = callSearch($retail->name, $warehouse->id);
    $row = collect($body['results'])->firstWhere('sku', $retail->sku);

    expect($row)->not->toBeNull()
        ->and($row['is_service'])->toBeFalse()
        ->and((float) $row['stock_qty'])->toBe(42.0);
});

it('StockGuard allows selling a service even when its inventory row is zero', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $svc = Product::where('sku', 'SVC-VAKRAB')->firstOrFail();

    $guard = new StockGuard(new UnitConverter);
    $check = $guard->canSell($svc->id, $warehouse->id, 1, null, Auth::user());

    expect($check['allowed'])->toBeTrue()
        ->and($check['requires_confirmation'])->toBeFalse();
});
