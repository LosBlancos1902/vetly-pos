<?php

use App\Http\Controllers\Inventory\StockOpnameController;
use App\Http\Controllers\POS\CashierController;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Journal;
use App\Models\Tenant\PendingStockMovement;
use App\Models\Tenant\Product;
use App\Models\Tenant\Sale;
use App\Models\Tenant\ServiceBundle;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\HppCalculator;
use App\Services\JournalEngine;
use App\Services\ServiceBundleService;
use App\Services\StockMovement as StockMovementService;
use App\Services\UnitConverter;
use App\Services\VetlySyncService;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

// ── Helpers ─────────────────────────────────────────────────────────────

function ownerForUpgrade(): TenantUser
{
    return TenantUser::query()
        ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
        ->firstOrFail();
}

function setStock(int $productId, int $warehouseId, float $qty, float $costAvg): void
{
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $productId, 'warehouse_id' => $warehouseId],
        ['qty' => $qty, 'cost_avg' => $costAvg, 'updated_at' => now(), 'created_at' => now()],
    );
    // CashierController reads $product->cost_avg dari products table (bukan inventories).
    // Set keduanya supaya test predictable.
    Product::where('id', $productId)->update(['cost_avg' => $costAvg]);
}

function getStockQty(int $productId, int $warehouseId): float
{
    return (float) (Inventory::withoutGlobalScopes()
        ->where('product_id', $productId)->where('warehouse_id', $warehouseId)
        ->value('qty') ?? 0);
}

function makeOpnameDraft(int $warehouseId): StockOpname
{
    Auth::login(ownerForUpgrade());
    $controller = app(StockOpnameController::class);
    $req = Request::create('/inventory/opnames', 'POST', [
        'warehouse_id' => $warehouseId,
        'opname_date' => now()->toDateString(),
    ]);
    $req->setUserResolver(fn () => Auth::user());
    $controller->store($req);

    return StockOpname::latest('id')->firstOrFail();
}

function doSale(Warehouse $warehouse, int $productId, float $qty, float $price): array
{
    $controller = new CashierController;
    $product = Product::findOrFail($productId);
    $request = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [[
            'product_id' => $productId,
            'unit_id' => $product->base_unit_id,
            'qty' => $qty,
            'price' => $price,
            'discount_amount' => 0,
        ]],
        'payments' => [['method' => 'cash', 'amount' => $qty * $price]],
    ]);
    $request->setUserResolver(fn () => Auth::user());

    $stock = new StockMovementService(new HppCalculator, new UnitConverter);
    $journal = new JournalEngine;
    $bundles = new ServiceBundleService($stock, new UnitConverter);
    $vetly = new VetlySyncService;

    $response = $controller->store($request, $stock, $journal, $bundles, $vetly);

    return json_decode($response->getContent(), true);
}

function completeOpname(StockOpname $opname, array $qtyPhysicalBySku): void
{
    Auth::login(ownerForUpgrade());
    $controller = app(StockOpnameController::class);

    // Set qty_physical via updateItems.
    $items = $opname->items()->with('product:id,sku')->get();
    $payload = [];
    foreach ($items as $it) {
        if (array_key_exists($it->product->sku, $qtyPhysicalBySku)) {
            $payload[] = ['id' => $it->id, 'qty_physical' => $qtyPhysicalBySku[$it->product->sku]];
        }
    }
    if ($payload !== []) {
        $req = Request::create('', 'PUT', ['items' => $payload]);
        $req->setUserResolver(fn () => Auth::user());
        $controller->updateItems($req, $opname);
    }

    // Complete.
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $controller->complete($req, $opname);
}

// ── beforeEach ──────────────────────────────────────────────────────────

beforeEach(function () {
    (new DefaultRolesSeeder)->run();

    // Bersihkan state — order penting karena FK.
    PendingStockMovement::query()->delete();
    StockOpname::query()->delete();
    Sale::query()->delete(); // cascade sales_items + sales_payments
    StockMovement::query()->withoutGlobalScopes()->delete();
    Journal::query()->delete();
});

// Penting: bersihkan SO + pending di AKHIR test juga, supaya test file LAIN
// (terutama POS/CashierServiceSaleTest yang jalan setelahnya) tidak melihat
// SO aktif sisa upgrade test dan nge-trigger deferral nggak sengaja.
afterEach(function () {
    PendingStockMovement::query()->delete();
    StockOpname::query()->delete();
});

// ── Tests ───────────────────────────────────────────────────────────────

it('CRITICAL REGRESSION: sale produk NON-SO tetap potong stok normal', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    setStock($p->id, $warehouse->id, 100, 5000);

    Auth::login(ownerForUpgrade());

    // Tidak ada SO aktif → flow harus 100% identik dengan sebelum upgrade.
    doSale($warehouse, $p->id, 3, 10000);

    expect(getStockQty($p->id, $warehouse->id))->toBe(97.0);

    // Tidak ada pending sama sekali.
    expect(PendingStockMovement::count())->toBe(0);

    // Stock movement type=sale ada (normal flow).
    $mv = StockMovement::withoutGlobalScopes()->where('product_id', $p->id)->where('type', 'sale')->first();
    expect($mv)->not->toBeNull()->and((float) $mv->qty)->toBe(3.0);
});

it('sale produk DALAM SO aktif → stok tidak berkurang + pending row dicatat', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    setStock($p->id, $warehouse->id, 100, 5000);

    // Buka SO untuk warehouse ini → produk SKU-001 jadi frozen.
    $opname = makeOpnameDraft($warehouse->id);

    Auth::login(ownerForUpgrade());
    doSale($warehouse, $p->id, 5, 10000);

    // Stok TIDAK berkurang (sistem tetap 100, fisik logically jadi 95).
    expect(getStockQty($p->id, $warehouse->id))->toBe(100.0);

    // Pending row tercatat.
    $pending = PendingStockMovement::where('opname_id', $opname->id)->first();
    expect($pending)->not->toBeNull()
        ->and((float) $pending->qty_base)->toBe(5.0)
        ->and((float) $pending->cost_per_base)->toBe(5000.0)
        ->and($pending->type)->toBe('sale')
        ->and($pending->applied_at)->toBeNull();

    // Tidak ada stock_movement type=sale untuk produk ini.
    $mv = StockMovement::withoutGlobalScopes()->where('product_id', $p->id)->where('type', 'sale')->first();
    expect($mv)->toBeNull();

    // HPP journal: untuk sale ini, retailCogs = 0 → tidak ada D 5100 line di journal.
    $journal = Journal::where('ref_type', Sale::class)->latest('id')->with('entries.coa')->first();
    $cogsLine = $journal->entries->first(fn ($e) => $e->coa->code === '5100');
    expect($cogsLine)->toBeNull(); // skipped karena retailCogs=0
});

it('SO di warehouse A → sale di warehouse B TIDAK kena defer (regression)', function () {
    $warehouseA = Warehouse::query()->firstOrFail();

    // Bikin warehouse B kalau belum ada.
    $warehouseB = Warehouse::firstOrCreate(
        ['code' => 'WH-B'],
        ['name' => 'Warehouse B', 'warehouse_type' => 'petshop', 'is_active' => true, 'address' => '-'],
    );

    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    setStock($p->id, $warehouseA->id, 100, 5000);
    setStock($p->id, $warehouseB->id, 50, 5000);

    // SO di warehouse A.
    makeOpnameDraft($warehouseA->id);

    Auth::login(ownerForUpgrade());

    // Sale di warehouse B → harus potong stok normal.
    doSale($warehouseB, $p->id, 5, 10000);

    expect(getStockQty($p->id, $warehouseB->id))->toBe(45.0)
        ->and(getStockQty($p->id, $warehouseA->id))->toBe(100.0); // A tidak terganggu

    expect(PendingStockMovement::count())->toBe(0);
});

it('scenario 100→95: fisik=sistem, pending dijalankan, no adjustment journal', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    setStock($p->id, $warehouse->id, 100, 5000);

    $opname = makeOpnameDraft($warehouse->id);

    // Kasir jual 5 (pending).
    Auth::login(ownerForUpgrade());
    doSale($warehouse, $p->id, 5, 10000);

    // Fisik = 100 (cocok dengan kondisi beku — sistem masih 100).
    completeOpname($opname, ['SKU-001' => 100]);

    // Stok akhir = 95 (sistem 100 - pending 5).
    expect(getStockQty($p->id, $warehouse->id))->toBe(95.0);

    // Adjustment journal TIDAK ada karena diff = 0.
    expect(Journal::where('ref_type', 'adjustment')->count())->toBe(0);

    // Deferred COGS journal ADA: D 5100 / C 1201, amount = 5 * 5000 = 25000.
    $deferred = Journal::where('ref_type', 'deferred_cogs')->latest('id')->with('entries.coa')->first();
    expect($deferred)->not->toBeNull();
    $byCoa = $deferred->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);
    expect($byCoa['5100']['debit'])->toBe(25000.0)
        ->and($byCoa['1201']['credit'])->toBe(25000.0);

    // Pending sudah ter-apply.
    expect(PendingStockMovement::where('applied_at', null)->count())->toBe(0);

    $applied = PendingStockMovement::first();
    expect($applied->applied_movement_id)->not->toBeNull();
});

it('scenario 100→93: loss 2 + pending 5 → 2 jurnal (adjustment + deferred)', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    setStock($p->id, $warehouse->id, 100, 5000);

    $opname = makeOpnameDraft($warehouse->id);

    Auth::login(ownerForUpgrade());
    doSale($warehouse, $p->id, 5, 10000); // pending

    // Fisik = 98 (loss 2 dari snapshot 100, MURNI loss karena petugas dianggap
    // hitung kondisi beku).
    completeOpname($opname, ['SKU-001' => 98]);

    // Stok akhir = 93 (sistem 100 → adjust minus 2 = 98 → minus pending 5 = 93).
    expect(getStockQty($p->id, $warehouse->id))->toBe(93.0);

    // Adjustment journal: D 5100 / C 1201, amount = 2 * 5000 = 10000.
    $adj = Journal::where('ref_type', 'adjustment')->latest('id')->with('entries.coa')->first();
    expect($adj)->not->toBeNull();
    $adjByCoa = $adj->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);
    expect($adjByCoa['5100']['debit'])->toBe(10000.0)
        ->and($adjByCoa['1201']['credit'])->toBe(10000.0);

    // Deferred journal: D 5100 / C 1201, amount = 5 * 5000 = 25000.
    $def = Journal::where('ref_type', 'deferred_cogs')->latest('id')->with('entries.coa')->first();
    $defByCoa = $def->entries->mapWithKeys(fn ($e) => [
        $e->coa->code => ['debit' => (float) $e->debit, 'credit' => (float) $e->credit],
    ]);
    expect($defByCoa['5100']['debit'])->toBe(25000.0)
        ->and($defByCoa['1201']['credit'])->toBe(25000.0);
});

it('multi-item: campuran frozen + non-frozen dalam 1 sale', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail();

    setStock($p1->id, $warehouse->id, 100, 5000);
    setStock($p2->id, $warehouse->id, 50, 2000);

    // SO snapshot — keduanya frozen (semua product punya inventory row di warehouse).
    $opname = makeOpnameDraft($warehouse->id);

    // Tapi delete pending opname_item untuk p2 → p2 jadi NON-frozen, p1 tetap frozen.
    $opname->items()->where('product_id', $p2->id)->delete();

    Auth::login(ownerForUpgrade());
    $controller = new CashierController;
    $request = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [
            ['product_id' => $p1->id, 'unit_id' => $p1->base_unit_id, 'qty' => 5, 'price' => 10000],
            ['product_id' => $p2->id, 'unit_id' => $p2->base_unit_id, 'qty' => 3, 'price' => 5000],
        ],
        'payments' => [['method' => 'cash', 'amount' => 65000]],
    ]);
    $request->setUserResolver(fn () => Auth::user());
    $stock = new StockMovementService(new HppCalculator, new UnitConverter);
    $controller->store($request, $stock, new JournalEngine, new ServiceBundleService($stock, new UnitConverter), new VetlySyncService);

    // p1 (frozen) → stok tetap 100, pending row dicatat.
    expect(getStockQty($p1->id, $warehouse->id))->toBe(100.0);
    expect(PendingStockMovement::where('product_id', $p1->id)->count())->toBe(1);

    // p2 (NOT frozen) → stok kepotong normal 50→47.
    expect(getStockQty($p2->id, $warehouse->id))->toBe(47.0);
    expect(PendingStockMovement::where('product_id', $p2->id)->count())->toBe(0);

    // Sale journal: retailCogs = p2 only = 3 * 2000 = 6000.
    $sale = Sale::latest('id')->first();
    $journal = Journal::where('ref_type', Sale::class)->where('ref_id', $sale->id)
        ->with('entries.coa')->first();
    $cogsDebit = (float) $journal->entries->first(fn ($e) => $e->coa->code === '5100')->debit;
    expect($cogsDebit)->toBe(6000.0);
});

it('service consumption: komponen frozen → defer ke pending type=service_consumption', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $svc = Product::where('sku', 'SVC-VAKRAB')->firstOrFail();
    $vial = Product::where('sku', 'RAW-VAKRAB')->firstOrFail();
    $spuit = Product::where('sku', 'RAW-SPUIT3')->firstOrFail();
    $kapas = Product::where('sku', 'RAW-KAPAS')->firstOrFail();

    // Set stok komponen.
    setStock($vial->id, $warehouse->id, 30, 75000);
    setStock($spuit->id, $warehouse->id, 100, 2500);
    setStock($kapas->id, $warehouse->id, 5, 35000);

    // SO snapshot → semua inventory rows ke-snap.
    $opname = makeOpnameDraft($warehouse->id);

    // Sisakan cuma RAW-VAKRAB di SO (sisanya un-freeze).
    $opname->items()->where('product_id', '!=', $vial->id)->delete();

    Auth::login(ownerForUpgrade());

    $bundle = ServiceBundle::where('name', 'Vaksin Rabies')->firstOrFail();
    \DB::table('service_bundle_items')->where('bundle_id', $bundle->id)->update(['is_optional' => false]);
    // Hapus sibling bundles untuk SVC-VAKRAB (test Clinic mungkin bikin yang lain
    // via latest('id') CashierController bisa pilih bundle salah).
    ServiceBundle::where('product_id', $svc->id)->where('id', '!=', $bundle->id)->delete();

    $controller = new CashierController;
    $request = Request::create('/pos/sales', 'POST', [
        'warehouse_id' => $warehouse->id,
        'items' => [[
            'product_id' => $svc->id, 'unit_id' => $svc->base_unit_id,
            'qty' => 1, 'price' => 250000,
        ]],
        'payments' => [['method' => 'cash', 'amount' => 250000]],
    ]);
    $request->setUserResolver(fn () => Auth::user());
    $stock = new StockMovementService(new HppCalculator, new UnitConverter);
    $controller->store($request, $stock, new JournalEngine, new ServiceBundleService($stock, new UnitConverter), new VetlySyncService);

    // RAW-VAKRAB (frozen) → stok tetap 30, pending dicatat.
    expect(getStockQty($vial->id, $warehouse->id))->toBe(30.0);
    $pendingVial = PendingStockMovement::where('product_id', $vial->id)->first();
    expect($pendingVial)->not->toBeNull()
        ->and($pendingVial->type)->toBe('service_consumption');

    // RAW-SPUIT3, RAW-KAPAS (un-frozen, exclude dari SO) → consume normal.
    expect(getStockQty($spuit->id, $warehouse->id))->toBeLessThan(100.0); // turun
    expect(PendingStockMovement::where('product_id', $spuit->id)->count())->toBe(0);
});

it('concurrent SO guard: tidak bisa bikin SO kedua di warehouse yang sama', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    makeOpnameDraft($warehouse->id);

    Auth::login(ownerForUpgrade());
    $controller = app(StockOpnameController::class);
    $req = Request::create('/inventory/opnames', 'POST', [
        'warehouse_id' => $warehouse->id,
        'opname_date' => now()->toDateString(),
    ]);
    $req->setUserResolver(fn () => Auth::user());

    expect(fn () => $controller->store($req))->toThrow(HttpException::class);
});

it('cannot create SO if pending unfinished from prior SO (covered by concurrent guard)', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $opname1 = makeOpnameDraft($warehouse->id);
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    setStock($p->id, $warehouse->id, 100, 5000);

    // Sale defer ke opname1.
    Auth::login(ownerForUpgrade());
    doSale($warehouse, $p->id, 3, 10000);

    expect(PendingStockMovement::where('opname_id', $opname1->id)->count())->toBe(1);

    // Coba bikin SO kedua → ditolak.
    $controller = app(StockOpnameController::class);
    $req = Request::create('/inventory/opnames', 'POST', [
        'warehouse_id' => $warehouse->id,
        'opname_date' => now()->toDateString(),
    ]);
    $req->setUserResolver(fn () => Auth::user());
    expect(fn () => $controller->store($req))->toThrow(HttpException::class);

    // Setelah complete opname1, baru bisa bikin SO baru.
    completeOpname($opname1, ['SKU-001' => 100]);
    $req2 = Request::create('/inventory/opnames', 'POST', [
        'warehouse_id' => $warehouse->id,
        'opname_date' => now()->toDateString(),
    ]);
    $req2->setUserResolver(fn () => Auth::user());
    $controller->store($req2); // should succeed
    expect(StockOpname::where('warehouse_id', $warehouse->id)->count())->toBeGreaterThan(1);
});

it('show() expose pendingSummary count + total_cogs akurat untuk dialog confirm', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    setStock($p->id, $warehouse->id, 100, 5000);

    $opname = makeOpnameDraft($warehouse->id);

    // Buat 3 sale tertahan: 2 dan 3 unit @ cost 5000 = 25000 HPP.
    Auth::login(ownerForUpgrade());
    doSale($warehouse, $p->id, 2, 10000); // pending 1
    doSale($warehouse, $p->id, 3, 10000); // pending 2

    // Hit show() endpoint via controller — Inertia::render dengan props pendingSummary.
    $controller = app(StockOpnameController::class);
    $req = Request::create('', 'GET');
    $req->setUserResolver(fn () => Auth::user());
    /** @var \Inertia\Response $response */
    $response = $controller->show($req, $opname);

    $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];
    expect($props['pendingSummary'])->toBeArray()
        ->and($props['pendingSummary']['count'])->toBe(2)
        ->and((float) $props['pendingSummary']['total_cogs'])->toBe(25000.0); // 2*5000 + 3*5000
});

it('show() pendingSummary nol kalau belum ada penjualan tertahan', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p = Product::where('sku', 'SKU-001')->firstOrFail();
    setStock($p->id, $warehouse->id, 100, 5000);

    $opname = makeOpnameDraft($warehouse->id);

    Auth::login(ownerForUpgrade());
    $controller = app(StockOpnameController::class);
    $req = Request::create('', 'GET');
    $req->setUserResolver(fn () => Auth::user());
    $response = $controller->show($req, $opname);

    $props = $response->toResponse(request())->getOriginalContent()->getData()['page']['props'];
    expect($props['pendingSummary']['count'])->toBe(0)
        ->and((float) $props['pendingSummary']['total_cogs'])->toBe(0.0);
});

it('Excel download: generate xlsx valid dengan header + baris produk benar', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail();

    setStock($p1->id, $warehouse->id, 50, 5000);
    setStock($p2->id, $warehouse->id, 25, 2000);

    $opname = makeOpnameDraft($warehouse->id);
    // Sisakan cuma 2 produk biar test deterministik.
    $opname->items()->whereNotIn('product_id', [$p1->id, $p2->id])->delete();

    Auth::login(ownerForUpgrade());
    $controller = app(StockOpnameController::class);
    $req = Request::create('', 'GET');
    $req->setUserResolver(fn () => Auth::user());

    $response = $controller->downloadExcel($req, $opname);

    // Response harus binary file download.
    expect($response->headers->get('Content-Type'))
        ->toContain('spreadsheetml');

    $tmpPath = $response->getFile()->getRealPath();
    expect(file_exists($tmpPath))->toBeTrue()
        ->and(filesize($tmpPath))->toBeGreaterThan(0);

    // Parse balik file yang baru di-generate — pastikan struktur valid + isi
    // sesuai snapshot.
    $loaded = IOFactory::load($tmpPath);
    $sheet = $loaded->getActiveSheet();

    // Header row.
    expect((string) $sheet->getCell('A1')->getValue())->toBe('Kode')
        ->and((string) $sheet->getCell('B1')->getValue())->toBe('Nama Produk')
        ->and((string) $sheet->getCell('C1')->getValue())->toBe('Satuan')
        ->and((string) $sheet->getCell('D1')->getValue())->toBe('Qty Sistem')
        ->and((string) $sheet->getCell('E1')->getValue())->toBe('Qty Fisik');

    // Header bold.
    expect($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue();

    // Baris produk — map by SKU karena order opname_items mungkin nggak fix.
    $rows = [];
    foreach (range(2, $sheet->getHighestRow()) as $r) {
        $sku = (string) $sheet->getCell('A'.$r)->getValue();
        if ($sku === '') continue;
        $rows[$sku] = [
            'name' => (string) $sheet->getCell('B'.$r)->getValue(),
            'qty_system' => (float) $sheet->getCell('D'.$r)->getValue(),
            'qty_fisik' => $sheet->getCell('E'.$r)->getValue(),
        ];
    }

    expect($rows)->toHaveCount(2);
    expect($rows['SKU-001']['qty_system'])->toBe(50.0)
        ->and($rows['SKU-001']['qty_fisik'])->toBeNull(); // kolom kosong
    expect($rows['SKU-002']['qty_system'])->toBe(25.0)
        ->and($rows['SKU-002']['qty_fisik'])->toBeNull();
});

it('Excel upload: parse xlsx → fill qty_physical via match SKU', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail();

    setStock($p1->id, $warehouse->id, 100, 5000);
    setStock($p2->id, $warehouse->id, 50, 2000);

    $opname = makeOpnameDraft($warehouse->id);

    // Bikin xlsx in-memory dengan format yang sama dengan download template.
    $ss = new Spreadsheet;
    $sh = $ss->getActiveSheet();
    $sh->setCellValue('A1', 'Kode');
    $sh->setCellValue('B1', 'Nama Produk');
    $sh->setCellValue('C1', 'Satuan');
    $sh->setCellValue('D1', 'Qty Sistem');
    $sh->setCellValue('E1', 'Qty Fisik');

    $sh->setCellValue('A2', 'SKU-001');
    $sh->setCellValue('E2', 97);
    $sh->setCellValue('A3', 'SKU-002');
    $sh->setCellValue('E3', 50);

    $tmpPath = tempnam(sys_get_temp_dir(), 'opname-upload-').'.xlsx';
    (new Xlsx($ss))->save($tmpPath);

    Auth::login(ownerForUpgrade());
    $controller = app(StockOpnameController::class);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($tmpPath, 'opname.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null, true));

    $controller->uploadExcel($req, $opname);

    $item1 = $opname->items()->where('product_id', $p1->id)->first();
    $item2 = $opname->items()->where('product_id', $p2->id)->first();

    expect((float) $item1->qty_physical)->toBe(97.0)
        ->and((float) $item1->qty_diff)->toBe(-3.0)
        ->and((float) $item2->qty_physical)->toBe(50.0)
        ->and((float) $item2->qty_diff)->toBe(0.0)
        ->and($opname->fresh()->status)->toBe('counting');
});

it('Excel upload: nilai 0 < qty < 0.01 di-skip + dilaporkan sebagai ambiguous (bukan dibuang diam-diam)', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail(); // qty valid
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail(); // qty ambiguous → skip
    $p3 = Product::where('sku', 'SKU-003')->firstOrFail(); // qty = 0 (stok habis, valid)

    setStock($p1->id, $warehouse->id, 100, 5000);
    setStock($p2->id, $warehouse->id, 50, 2000);
    setStock($p3->id, $warehouse->id, 30, 1000);

    $opname = makeOpnameDraft($warehouse->id);

    $ss = new Spreadsheet;
    $sh = $ss->getActiveSheet();
    $sh->setCellValue('A1', 'Kode');
    $sh->setCellValue('B1', 'Nama Produk');
    $sh->setCellValue('C1', 'Satuan');
    $sh->setCellValue('D1', 'Qty Sistem');
    $sh->setCellValue('E1', 'Qty Fisik');

    $sh->setCellValue('A2', 'SKU-001');
    $sh->setCellValue('E2', 97);          // valid → imported
    $sh->setCellValue('A3', 'SKU-002');
    $sh->setCellValue('E3', 0.0002);      // ambiguous → skipped + reported
    $sh->setCellValue('A4', 'SKU-003');
    $sh->setCellValue('E4', 0);           // valid (stok habis)

    $tmpPath = tempnam(sys_get_temp_dir(), 'opname-amb-').'.xlsx';
    (new Xlsx($ss))->save($tmpPath);

    Auth::login(ownerForUpgrade());
    $controller = app(StockOpnameController::class);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($tmpPath, 'opname.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null, true));

    $response = $controller->uploadExcel($req, $opname);

    // p1 dan p3 ke-fill, p2 di-skip (qty_physical tetap null).
    $item1 = $opname->items()->where('product_id', $p1->id)->first();
    $item2 = $opname->items()->where('product_id', $p2->id)->first();
    $item3 = $opname->items()->where('product_id', $p3->id)->first();

    expect((float) $item1->qty_physical)->toBe(97.0)
        ->and($item2->qty_physical)->toBeNull()                  // ambiguous SKIPPED
        ->and((float) $item3->qty_physical)->toBe(0.0);          // 0 = stok habis, valid

    // Flash message harus mention SKU yang di-skip (jangan silent).
    $flash = session('success');
    expect($flash)->toBeString()
        ->and($flash)->toContain('SKU-002')
        ->and($flash)->toContain('diabaikan')
        ->and($flash)->toContain('typo');
});

it('Excel upload: file dengan SEMUA baris ambiguous tetap diabaikan tapi tidak crash', function () {
    $warehouse = Warehouse::query()->firstOrFail();
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    setStock($p1->id, $warehouse->id, 100, 5000);

    $opname = makeOpnameDraft($warehouse->id);

    $ss = new Spreadsheet;
    $sh = $ss->getActiveSheet();
    $sh->setCellValue('A1', 'Kode');
    $sh->setCellValue('E1', 'Qty Fisik');
    $sh->setCellValue('A2', 'SKU-001');
    $sh->setCellValue('E2', 0.0002);

    $tmpPath = tempnam(sys_get_temp_dir(), 'opname-all-amb-').'.xlsx';
    (new Xlsx($ss))->save($tmpPath);

    Auth::login(ownerForUpgrade());
    $controller = app(StockOpnameController::class);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($tmpPath, 'opname.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null, true));

    $controller->uploadExcel($req, $opname);

    $item = $opname->items()->where('product_id', $p1->id)->first();
    expect($item->qty_physical)->toBeNull();

    $flash = session('success');
    expect($flash)->toContain('0 item terisi')
        ->and($flash)->toContain('SKU-001');
});
