<?php

declare(strict_types=1);

use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\MasterData\CustomerController;
use App\Http\Controllers\MasterData\PaymentTermController;
use App\Http\Controllers\MasterData\ProductCategoryController;
use App\Http\Controllers\MasterData\ProductController;
use App\Http\Controllers\MasterData\SupplierController;
use App\Http\Controllers\MasterData\TaxRateController;
use App\Http\Controllers\MasterData\UnitController;
use App\Http\Controllers\MasterData\WarehouseController;
use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Guest authentication routes
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

// Authenticated platform routes
Route::middleware([
    'auth',
    AuthenticateSession::class,
    EnforceSessionTimeouts::class,
    EnsureUserIsActive::class,
])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('/account/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('password.update');

    // Master Data routes
    Route::prefix('master')->name('master.')->group(function (): void {
        // Units
        Route::get('units', [UnitController::class, 'index'])->middleware('can:units.view')->name('units.index');
        Route::get('units/create', [UnitController::class, 'create'])->middleware('can:units.create')->name('units.create');
        Route::post('units', [UnitController::class, 'store'])->middleware('can:units.create')->name('units.store');
        Route::get('units/{unit}/edit', [UnitController::class, 'edit'])->middleware('can:units.update')->name('units.edit');
        Route::put('units/{unit}', [UnitController::class, 'update'])->middleware('can:units.update')->name('units.update');
        Route::delete('units/{unit}', [UnitController::class, 'destroy'])->middleware('can:units.delete')->name('units.destroy');

        // Product Categories
        Route::get('product-categories', [ProductCategoryController::class, 'index'])->middleware('can:product_categories.view')->name('product-categories.index');
        Route::get('product-categories/create', [ProductCategoryController::class, 'create'])->middleware('can:product_categories.create')->name('product-categories.create');
        Route::post('product-categories', [ProductCategoryController::class, 'store'])->middleware('can:product_categories.create')->name('product-categories.store');
        Route::get('product-categories/{product_category}/edit', [ProductCategoryController::class, 'edit'])->middleware('can:product_categories.update')->name('product-categories.edit');
        Route::put('product-categories/{product_category}', [ProductCategoryController::class, 'update'])->middleware('can:product_categories.update')->name('product-categories.update');
        Route::delete('product-categories/{product_category}', [ProductCategoryController::class, 'destroy'])->middleware('can:product_categories.delete')->name('product-categories.destroy');

        // Products
        Route::get('products', [ProductController::class, 'index'])->middleware('can:products.view')->name('products.index');
        Route::get('products/create', [ProductController::class, 'create'])->middleware('can:products.create')->name('products.create');
        Route::post('products', [ProductController::class, 'store'])->middleware('can:products.create')->name('products.store');
        Route::get('products/{product}/edit', [ProductController::class, 'edit'])->middleware('can:products.update')->name('products.edit');
        Route::put('products/{product}', [ProductController::class, 'update'])->middleware('can:products.update')->name('products.update');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('can:products.delete')->name('products.destroy');

        // Warehouses
        Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('can:warehouses.view')->name('warehouses.index');
        Route::get('warehouses/create', [WarehouseController::class, 'create'])->middleware('can:warehouses.create')->name('warehouses.create');
        Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('can:warehouses.create')->name('warehouses.store');
        Route::get('warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->middleware('can:warehouses.update')->name('warehouses.edit');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('can:warehouses.update')->name('warehouses.update');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('can:warehouses.delete')->name('warehouses.destroy');

        // Suppliers
        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('can:suppliers.view')->name('suppliers.index');
        Route::get('suppliers/create', [SupplierController::class, 'create'])->middleware('can:suppliers.create')->name('suppliers.create');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:suppliers.create')->name('suppliers.store');
        Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->middleware('can:suppliers.update')->name('suppliers.edit');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('can:suppliers.update')->name('suppliers.update');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('can:suppliers.delete')->name('suppliers.destroy');

        // Customers
        Route::get('customers', [CustomerController::class, 'index'])->middleware('can:customers.view')->name('customers.index');
        Route::get('customers/create', [CustomerController::class, 'create'])->middleware('can:customers.create')->name('customers.create');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('can:customers.create')->name('customers.store');
        Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])->middleware('can:customers.update')->name('customers.edit');
        Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('can:customers.update')->name('customers.update');
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('can:customers.delete')->name('customers.destroy');

        // Tax Rates
        Route::get('tax-rates', [TaxRateController::class, 'index'])->middleware('can:tax_rates.view')->name('tax-rates.index');
        Route::get('tax-rates/create', [TaxRateController::class, 'create'])->middleware('can:tax_rates.create')->name('tax-rates.create');
        Route::post('tax-rates', [TaxRateController::class, 'store'])->middleware('can:tax_rates.create')->name('tax-rates.store');
        Route::get('tax-rates/{tax_rate}/edit', [TaxRateController::class, 'edit'])->middleware('can:tax_rates.update')->name('tax-rates.edit');
        Route::put('tax-rates/{tax_rate}', [TaxRateController::class, 'update'])->middleware('can:tax_rates.update')->name('tax-rates.update');
        Route::delete('tax-rates/{tax_rate}', [TaxRateController::class, 'destroy'])->middleware('can:tax_rates.delete')->name('tax-rates.destroy');

        // Payment Terms
        Route::get('payment-terms', [PaymentTermController::class, 'index'])->middleware('can:payment_terms.view')->name('payment-terms.index');
        Route::get('payment-terms/create', [PaymentTermController::class, 'create'])->middleware('can:payment_terms.create')->name('payment-terms.create');
        Route::post('payment-terms', [PaymentTermController::class, 'store'])->middleware('can:payment_terms.create')->name('payment-terms.store');
        Route::get('payment-terms/{payment_term}/edit', [PaymentTermController::class, 'edit'])->middleware('can:payment_terms.update')->name('payment-terms.edit');
        Route::put('payment-terms/{payment_term}', [PaymentTermController::class, 'update'])->middleware('can:payment_terms.update')->name('payment-terms.update');
        Route::delete('payment-terms/{payment_term}', [PaymentTermController::class, 'destroy'])->middleware('can:payment_terms.delete')->name('payment-terms.destroy');
    });
});

// Development styleguide route (strictly local only)
if (app()->environment('local')) {
    Route::middleware([
        'web',
        'auth',
        AuthenticateSession::class,
        EnforceSessionTimeouts::class,
        EnsureUserIsActive::class,
    ])->get('/dev/styleguide', function () {
        return Inertia::render('Dev/Styleguide');
    })->name('dev.styleguide');
}
