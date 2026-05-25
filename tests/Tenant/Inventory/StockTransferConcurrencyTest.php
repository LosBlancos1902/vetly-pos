<?php

use App\Http\Controllers\Inventory\StockTransferController;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Journal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement as StockMovementModel;
use App\Models\Tenant\StockTransfer;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Concurrency: 2 parallel ship dari source yang sama race for last unit.
 * Salah satu wins, satu dapat InsufficientStockException + atomic rollback.
 *
 * Setup: source punya exactly 1 unit. Dua child process ship 1 unit ke dest
 * berbeda secara paralel. Pakai pcntl_fork (sama pola dgn StockMovementConcurrencyTest).
 */

function resetForTransferRace(): array
{
    (new DefaultRolesSeeder)->run();

    $owner = TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
    $product = Product::with('units')->where('sku', 'SKU-001')->firstOrFail();

    $src = Warehouse::firstOrCreate(
        ['code' => 'WHT-TRF-RACE-SRC'],
        ['name' => 'Race Src', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    $dstA = Warehouse::firstOrCreate(
        ['code' => 'WHT-TRF-RACE-A'],
        ['name' => 'Race Dst A', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );
    $dstB = Warehouse::firstOrCreate(
        ['code' => 'WHT-TRF-RACE-B'],
        ['name' => 'Race Dst B', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );

    // Cleanup transfer + jurnal + movements dari run sebelumnya.
    $whIds = [$src->id, $dstA->id, $dstB->id];
    $transferIds = StockTransfer::whereIn('source_warehouse_id', $whIds)
        ->orWhereIn('dest_warehouse_id', $whIds)->pluck('id');
    StockMovementModel::query()->withoutGlobalScopes()
        ->whereIn('warehouse_id', $whIds)
        ->where('ref_type', StockTransfer::class)->delete();
    $journalIds = Journal::whereIn('ref_type', ['transfer_ship', 'transfer_receive'])->pluck('id');
    JournalEntry::whereIn('journal_id', $journalIds)->delete();
    Journal::whereIn('id', $journalIds)->delete();
    StockTransfer::whereIn('id', $transferIds)->delete();

    // Source = exactly 1 unit @ 5000.
    Inventory::query()->withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $product->id, 'warehouse_id' => $src->id],
        ['qty' => 1, 'cost_avg' => 5000, 'updated_at' => now(), 'created_at' => now()],
    );

    return [$owner, $product, $src, $dstA, $dstB];
}

it('TRANSFER RACE: 2 parallel ship of last unit → exactly 1 sukses, 1 InsufficientStock', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork not available.');
    }

    [$owner, $product, $src, $dstA, $dstB] = resetForTransferRace();

    DB::disconnect(); // children get fresh connections after fork

    $tmpA = tempnam(sys_get_temp_dir(), 'trf_race_a_');
    $tmpB = tempnam(sys_get_temp_dir(), 'trf_race_b_');

    $run = function (string $outFile, int $destId) use ($owner, $product, $src) {
        try {
            Auth::login($owner->fresh());
            $controller = app(StockTransferController::class);
            $req = Request::create('/inventory/transfers', 'POST', [
                'source_warehouse_id' => $src->id,
                'dest_warehouse_id' => $destId,
                'items' => [['product_id' => $product->id, 'qty' => 1]],
            ]);
            $req->setUserResolver(fn () => Auth::user());
            $controller->store($req);
            file_put_contents($outFile, 'OK');
        } catch (\App\Exceptions\InsufficientStockException $e) {
            file_put_contents($outFile, 'INSUFFICIENT');
        } catch (\Throwable $e) {
            file_put_contents($outFile, 'ERR:'.get_class($e).':'.$e->getMessage());
        }
        exit(0);
    };

    $pidA = pcntl_fork();
    if ($pidA === 0) {
        $run($tmpA, $dstA->id);
    }
    $pidB = pcntl_fork();
    if ($pidB === 0) {
        $run($tmpB, $dstB->id);
    }

    pcntl_waitpid($pidA, $statusA);
    pcntl_waitpid($pidB, $statusB);

    $resultA = file_get_contents($tmpA);
    $resultB = file_get_contents($tmpB);
    @unlink($tmpA);
    @unlink($tmpB);

    $outcomes = [$resultA, $resultB];
    sort($outcomes);

    expect($outcomes)->toBe(['INSUFFICIENT', 'OK'])
        // Source qty harus 0 (exactly 1 transfer consumed the unit)
        ->and((float) Inventory::query()->withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $src->id)->value('qty'))->toBe(0.0)
        // Exactly 1 transfer row sukses
        ->and(StockTransfer::whereIn('source_warehouse_id', [$src->id])->count())->toBe(1);
});
