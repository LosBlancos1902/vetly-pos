<?php

use App\Exceptions\InsufficientStockException;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\StockMovement;
use App\Services\UnitConverter;
use Illuminate\Support\Facades\DB;

/**
 * Reset a known product to exactly 1 base unit before each scenario.
 */
function resetProductForRace(): array
{
    $product = Product::with('units')->where('sku', 'SKU-001')->firstOrFail();
    $warehouse = Warehouse::query()->firstOrFail();

    Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->update(['qty' => 1]);

    StockMovementModel::query()->withoutGlobalScopes()
        ->where('product_id', $product->id)
        ->delete();

    return [$product, $warehouse];
}

it('throws InsufficientStockException when stock is exhausted by an earlier locked sale (sequential)', function () {
    [$product, $warehouse] = resetProductForRace();

    $service = new StockMovement(new HppCalculator, new UnitConverter);

    // First sale: consumes the 1 unit.
    $service->record($product, $warehouse, 'sale', 1, 0);

    // Second sale: must throw — no override permission, no allow_minus on product.
    expect(fn () => $service->record($product, $warehouse, 'sale', 1, 0))
        ->toThrow(InsufficientStockException::class);

    expect((float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('qty'))->toBe(0.0);
});

it('does not oversell under two parallel processes racing for the last unit', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork not available.');
    }

    [$product, $warehouse] = resetProductForRace();

    // Close parent connections so children get fresh ones after fork.
    DB::disconnect();

    $tmpA = tempnam(sys_get_temp_dir(), 'race_a_');
    $tmpB = tempnam(sys_get_temp_dir(), 'race_b_');

    $run = function (string $outFile) use ($product, $warehouse) {
        // Each child re-resolves the service so DB connection is fresh.
        $service = new StockMovement(new HppCalculator, new UnitConverter);
        try {
            $service->record($product->fresh()->load('units'), $warehouse->fresh(), 'sale', 1, 0);
            file_put_contents($outFile, 'OK');
        } catch (InsufficientStockException $e) {
            file_put_contents($outFile, 'INSUFFICIENT');
        } catch (\Throwable $e) {
            file_put_contents($outFile, 'ERR:'.$e->getMessage());
        }
        exit(0);
    };

    $pidA = pcntl_fork();
    if ($pidA === 0) {
        $run($tmpA);
    }

    $pidB = pcntl_fork();
    if ($pidB === 0) {
        $run($tmpB);
    }

    pcntl_waitpid($pidA, $statusA);
    pcntl_waitpid($pidB, $statusB);

    $resultA = file_get_contents($tmpA);
    $resultB = file_get_contents($tmpB);
    @unlink($tmpA);
    @unlink($tmpB);

    // Exactly one child commits the sale; the other hits InsufficientStockException.
    $outcomes = [$resultA, $resultB];
    sort($outcomes);

    expect($outcomes)->toBe(['INSUFFICIENT', 'OK']);

    expect((float) Inventory::query()->withoutGlobalScopes()
        ->where('product_id', $product->id)
        ->where('warehouse_id', $warehouse->id)
        ->value('qty'))->toBe(0.0);

    expect(StockMovementModel::query()->withoutGlobalScopes()
        ->where('product_id', $product->id)
        ->count())->toBe(1);
});
