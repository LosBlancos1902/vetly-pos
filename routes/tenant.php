<?php

declare(strict_types=1);

use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Api\Vetly\VetlyWebhookController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\StockCardController;
use App\Http\Controllers\Inventory\StockController;
use App\Http\Controllers\Master\CompoundController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\Master\ServiceController;
use App\Http\Controllers\Master\SupplierController;
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

        // Master data
        Route::resource('master/products', ProductController::class)
            ->names('master.products')->only(['index', 'store', 'update', 'destroy']);

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
