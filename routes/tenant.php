<?php

declare(strict_types=1);

use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Api\Vetly\VetlyWebhookController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\StockCardController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Inventory\StockOpnameController;
use App\Http\Controllers\Master\CompoundController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\Master\ServiceController;
use App\Http\Controllers\Master\SupplierController;
use App\Http\Controllers\Purchasing\AccountsPayableController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\PurchaseRequestController;
use App\Http\Controllers\Pharmacy\CompoundController as PharmacyCompoundController;
use App\Http\Controllers\POS\CashierController;
use App\Http\Controllers\POS\ShiftController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Sales\SaleController;
use App\Http\Controllers\Settings\TenantSettingsController;
use App\Http\Controllers\Settings\RoleController as SettingsRoleController;
use App\Http\Controllers\Settings\UserController as SettingsUserController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes — the Vetly POS application ({tenant}.vetly-pos.test)
|--------------------------------------------------------------------------
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    // Breeze auth (login/logout/register/password) — runs in tenant DB context.
    require __DIR__.'/auth.php';

    Route::middleware(['auth'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Profile (Breeze)
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        // POS
        Route::prefix('pos')->name('pos.')->group(function () {
            Route::get('/', [CashierController::class, 'index'])->name('cashier');
            Route::get('/products/scan/{barcode}', [CashierController::class, 'scan'])->name('scan');
            Route::get('/products/search', [CashierController::class, 'search'])->name('search');
            Route::post('/sales', [CashierController::class, 'store'])->name('sales.store');
            Route::get('/sales/{sale}/receipt', [CashierController::class, 'receipt'])->name('receipt');
            Route::post('/shifts/open', [ShiftController::class, 'open'])->name('shifts.open');
            Route::post('/shifts/close', [ShiftController::class, 'close'])->name('shifts.close');
        });

        // Master data — Excel import (sebelum resource route products supaya
        // tidak ke-shadow oleh /master/products/{product}).
        Route::get('/master/products/import',
            [\App\Http\Controllers\Master\ProductImportController::class, 'show'])
            ->name('master.products.import.show')
            ->middleware('can:master.manage');
        Route::get('/master/products/import/template',
            [\App\Http\Controllers\Master\ProductImportController::class, 'downloadTemplate'])
            ->name('master.products.import.template')
            ->middleware('can:master.manage');
        Route::post('/master/products/import/preview',
            [\App\Http\Controllers\Master\ProductImportController::class, 'preview'])
            ->name('master.products.import.preview')
            ->middleware('can:master.manage');
        Route::post('/master/products/import/commit',
            [\App\Http\Controllers\Master\ProductImportController::class, 'commit'])
            ->name('master.products.import.commit')
            ->middleware('can:master.manage');

        Route::resource('master/products', ProductController::class)
            ->names('master.products')
            ->only(['index', 'show', 'store', 'update', 'destroy']);

        Route::resource('master/categories', \App\Http\Controllers\Master\CategoryController::class)
            ->parameters(['categories' => 'category'])
            ->names('master.categories')
            ->only(['index', 'store', 'update', 'destroy'])
            ->middleware('can:master.manage');

        // Master pelanggan (CRM)
        Route::get('/master/customers/search',
            [\App\Http\Controllers\Master\CustomerController::class, 'search'])
            ->name('master.customers.search')
            ->middleware('can:customer.manage');
        Route::post('/master/customers/quick-store',
            [\App\Http\Controllers\Master\CustomerController::class, 'quickStore'])
            ->name('master.customers.quick_store')
            ->middleware('can:customer.manage');
        Route::resource('master/customers', \App\Http\Controllers\Master\CustomerController::class)
            ->parameters(['customers' => 'customer'])
            ->names('master.customers')
            ->only(['index', 'store', 'update', 'destroy'])
            ->middleware('can:customer.manage');

        // Price tiers (multi-tier dinamis: Eceran/Grosir/Klinik/dll).
        // Tier default tidak bisa di-destroy (guard di controller).
        Route::post('/master/price-tiers', [\App\Http\Controllers\Master\PriceTierController::class, 'store'])
            ->name('master.price_tiers.store')
            ->middleware('can:master.manage');
        Route::put('/master/price-tiers/{tier}', [\App\Http\Controllers\Master\PriceTierController::class, 'update'])
            ->name('master.price_tiers.update')
            ->middleware('can:master.manage');
        Route::delete('/master/price-tiers/{tier}', [\App\Http\Controllers\Master\PriceTierController::class, 'destroy'])
            ->name('master.price_tiers.destroy')
            ->middleware('can:master.manage');
        Route::post('/master/price-tiers/{tier}/set-default', [\App\Http\Controllers\Master\PriceTierController::class, 'setDefault'])
            ->name('master.price_tiers.set_default')
            ->middleware('can:master.manage');

        Route::resource('master/compounds', CompoundController::class)
            ->parameters(['compounds' => 'recipe'])
            ->names('master.compounds')->only(['index', 'store', 'update', 'destroy'])
            ->middleware('can:master.compounds');

        Route::resource('master/services', ServiceController::class)
            ->parameters(['services' => 'bundle'])
            ->names('master.services')->only(['index', 'store', 'update', 'destroy'])
            ->middleware('can:master.services');

        Route::resource('master/suppliers', SupplierController::class)
            ->names('master.suppliers')->only(['index', 'store', 'update', 'destroy'])
            ->middleware('can:purchasing.supplier_manage');

        // Purchasing — Purchase Requests
        Route::prefix('purchasing')->name('purchasing.')->group(function () {
            Route::get('/requests', [PurchaseRequestController::class, 'index'])
                ->name('requests.index');
            Route::post('/requests', [PurchaseRequestController::class, 'store'])
                ->middleware('can:purchasing.pr_create')->name('requests.store');
            Route::post('/requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])
                ->middleware('can:purchasing.pr_create')->name('requests.submit');
            Route::post('/requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])
                ->middleware('can:purchasing.pr_approve')->name('requests.approve');
            Route::post('/requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])
                ->middleware('can:purchasing.pr_approve')->name('requests.reject');

            // Purchase Orders
            Route::get('/orders', [PurchaseOrderController::class, 'index'])
                ->name('orders.index');
            Route::post('/orders', [PurchaseOrderController::class, 'store'])
                ->middleware('can:purchasing.po_create')->name('orders.store');
            Route::put('/orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
                ->middleware('can:purchasing.po_create')->name('orders.update');
            Route::post('/orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])
                ->middleware('can:purchasing.po_create')->name('orders.submit');
            Route::post('/orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
                ->middleware('can:purchasing.po_approve')->name('orders.approve');
            Route::post('/orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])
                ->middleware('can:purchasing.po_approve')->name('orders.reject');
            Route::post('/orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])
                ->name('orders.cancel');

            // Goods Receipts
            Route::get('/receipts', [GoodsReceiptController::class, 'index'])
                ->middleware('can:purchasing.receive')->name('receipts.index');
            Route::get('/receipts/create/{purchaseOrder}', [GoodsReceiptController::class, 'create'])
                ->middleware('can:purchasing.receive')->name('receipts.create');
            Route::post('/receipts', [GoodsReceiptController::class, 'store'])
                ->middleware('can:purchasing.receive')->name('receipts.store');

            // Accounts Payable (hutang supplier)
            Route::get('/payables', [AccountsPayableController::class, 'index'])
                ->middleware('can:purchasing.ap_view')->name('payables.index');
            Route::post('/payables/{accountsPayable}/pay', [AccountsPayableController::class, 'pay'])
                ->middleware('can:purchasing.ap_pay')->name('payables.pay');
        });

        // Pharmacy — compound execution (racikan)
        Route::middleware('can:pharmacy.compound')->prefix('pharmacy')->name('pharmacy.')->group(function () {
            Route::get('/compound', [PharmacyCompoundController::class, 'index'])->name('compound.index');
            Route::get('/compound/preview', [PharmacyCompoundController::class, 'preview'])->name('compound.preview');
            Route::post('/compound/execute', [PharmacyCompoundController::class, 'execute'])->name('compound.execute');
        });

        // Inventory
        Route::get('/inventory/stock', [StockController::class, 'index'])->name('inventory.stock');
        Route::post('/inventory/adjustment', [StockController::class, 'adjust'])->name('inventory.adjustment');
        Route::get('/inventory/stock-card/{product}', [StockCardController::class, 'show'])
            ->middleware('can:inventory.view')->name('inventory.stock_card');

        // Stock Opname
        Route::middleware('can:inventory.opname')->prefix('inventory/opnames')
            ->name('inventory.opnames.')->group(function () {
                Route::get('/', [StockOpnameController::class, 'index'])->name('index');
                Route::get('/create', [StockOpnameController::class, 'create'])->name('create');
                Route::post('/', [StockOpnameController::class, 'store'])->name('store');
                Route::get('/{opname}', [StockOpnameController::class, 'show'])->name('show');
                Route::put('/{opname}/items', [StockOpnameController::class, 'updateItems'])->name('update_items');
                Route::post('/{opname}/complete', [StockOpnameController::class, 'complete'])->name('complete');
                Route::post('/{opname}/cancel', [StockOpnameController::class, 'cancel'])->name('cancel');
                Route::get('/{opname}/excel', [StockOpnameController::class, 'downloadExcel'])->name('excel.download');
                Route::post('/{opname}/excel', [StockOpnameController::class, 'uploadExcel'])->name('excel.upload');
            });

        // Sales history
        Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');

        // Accounting
        Route::get('/accounting/journal', [JournalController::class, 'index'])->name('accounting.journal');

        // Settings
        Route::get('/settings/tenant', [TenantSettingsController::class, 'index'])
            ->middleware('can:settings.tenant')->name('settings.tenant');

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::middleware('can:settings.users')->group(function () {
                Route::get('/users', [SettingsUserController::class, 'index'])->name('users.index');
                Route::post('/users', [SettingsUserController::class, 'store'])->name('users.store');
                Route::put('/users/{user}', [SettingsUserController::class, 'update'])->name('users.update');
                Route::delete('/users/{user}', [SettingsUserController::class, 'destroy'])->name('users.destroy');
            });
            Route::middleware('can:settings.roles')->group(function () {
                Route::get('/roles', [SettingsRoleController::class, 'index'])->name('roles.index');
                Route::put('/roles/{role}', [SettingsRoleController::class, 'update'])->name('roles.update');
            });
        });
    });

    // Vetly inbound webhook (token-guarded inside the controller).
    Route::post('/api/vetly/webhook/customer', [VetlyWebhookController::class, 'customer'])
        ->name('api.vetly.customer');
});
