<?php

declare(strict_types=1);

use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventory\StockAdjustmentController;
use App\Http\Controllers\Inventory\StockBalanceController;
use App\Http\Controllers\Inventory\StockCardController;
use App\Http\Controllers\Inventory\StockMovementController;
use App\Http\Controllers\MasterData\CustomerController;
use App\Http\Controllers\MasterData\PaymentTermController;
use App\Http\Controllers\MasterData\ProductCategoryController;
use App\Http\Controllers\MasterData\ProductController;
use App\Http\Controllers\MasterData\SupplierController;
use App\Http\Controllers\MasterData\TaxRateController;
use App\Http\Controllers\MasterData\UnitController;
use App\Http\Controllers\MasterData\WarehouseController;
use App\Http\Controllers\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Sales\DeliveryOrderController;
use App\Http\Controllers\Sales\SalesOrderController;
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
    'throttle:erp-write',
])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

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

    // Inventory routes
    Route::prefix('inventory')->name('inventory.')->group(function (): void {
        Route::get('stock-balances', [StockBalanceController::class, 'index'])->middleware('can:stock_balances.view')->name('stock-balances.index');
        Route::get('stock-movements', [StockMovementController::class, 'index'])->middleware('can:stock_movements.view')->name('stock-movements.index');
        Route::get('stock-card', [StockCardController::class, 'index'])->middleware('can:stock_movements.view')->name('stock-card.index');

        // Stock Adjustments
        Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->middleware('can:stock_adjustments.view')->name('stock-adjustments.index');
        Route::get('stock-adjustments/create', [StockAdjustmentController::class, 'create'])->middleware('can:stock_adjustments.create')->name('stock-adjustments.create');
        Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])->middleware('can:stock_adjustments.create')->name('stock-adjustments.store');
        Route::get('stock-adjustments/{stock_adjustment}', [StockAdjustmentController::class, 'show'])->middleware('can:stock_adjustments.view')->name('stock-adjustments.show');
        Route::get('stock-adjustments/{stock_adjustment}/edit', [StockAdjustmentController::class, 'edit'])->middleware('can:stock_adjustments.update')->name('stock-adjustments.edit');
        Route::put('stock-adjustments/{stock_adjustment}', [StockAdjustmentController::class, 'update'])->middleware('can:stock_adjustments.update')->name('stock-adjustments.update');
        Route::post('stock-adjustments/{stock_adjustment}/post', [StockAdjustmentController::class, 'post'])->middleware('can:stock_adjustments.post')->name('stock-adjustments.post');
        Route::post('stock-adjustments/{stock_adjustment}/void', [StockAdjustmentController::class, 'void'])->middleware('can:stock_adjustments.void')->name('stock-adjustments.void');
    });

    // Purchasing module routes
    Route::prefix('purchasing')->name('purchasing.')->group(function (): void {
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('can:purchase_orders.view')->name('purchase-orders.index');
        Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])->middleware('can:purchase_orders.create')->name('purchase-orders.create');
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('can:purchase_orders.create')->name('purchase-orders.store');
        Route::get('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])->middleware('can:purchase_orders.view')->name('purchase-orders.show');
        Route::get('purchase-orders/{purchase_order}/edit', [PurchaseOrderController::class, 'edit'])->middleware('can:purchase_orders.update')->name('purchase-orders.edit');
        Route::put('purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update'])->middleware('can:purchase_orders.update')->name('purchase-orders.update');
        Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])->middleware('can:purchase_orders.approve')->name('purchase-orders.approve');
        Route::post('purchase-orders/{purchase_order}/void', [PurchaseOrderController::class, 'void'])->middleware('can:purchase_orders.void')->name('purchase-orders.void');
        Route::post('purchase-orders/{purchase_order}/close', [PurchaseOrderController::class, 'close'])->middleware('can:purchase_orders.update')->name('purchase-orders.close');

        Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('can:goods_receipts.view')->name('goods-receipts.index');
        Route::get('goods-receipts/create', [GoodsReceiptController::class, 'create'])->middleware('can:goods_receipts.create')->name('goods-receipts.create');
        Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('can:goods_receipts.create')->name('goods-receipts.store');
        Route::get('goods-receipts/{goods_receipt}', [GoodsReceiptController::class, 'show'])->middleware('can:goods_receipts.view')->name('goods-receipts.show');
        Route::get('goods-receipts/{goods_receipt}/edit', [GoodsReceiptController::class, 'edit'])->middleware('can:goods_receipts.update')->name('goods-receipts.edit');
        Route::put('goods-receipts/{goods_receipt}', [GoodsReceiptController::class, 'update'])->middleware('can:goods_receipts.update')->name('goods-receipts.update');
        Route::post('goods-receipts/{goods_receipt}/post', [GoodsReceiptController::class, 'post'])->middleware('can:goods_receipts.post')->name('goods-receipts.post');
        Route::post('goods-receipts/{goods_receipt}/void', [GoodsReceiptController::class, 'void'])->middleware('can:goods_receipts.void')->name('goods-receipts.void');
    });

    // Sales module routes
    Route::prefix('sales')->name('sales.')->group(function (): void {
        Route::get('sales-orders', [SalesOrderController::class, 'index'])->middleware('can:sales_orders.view')->name('sales-orders.index');
        Route::get('sales-orders/create', [SalesOrderController::class, 'create'])->middleware('can:sales_orders.create')->name('sales-orders.create');
        Route::post('sales-orders', [SalesOrderController::class, 'store'])->middleware('can:sales_orders.create')->name('sales-orders.store');
        Route::get('sales-orders/{sales_order}', [SalesOrderController::class, 'show'])->middleware('can:sales_orders.view')->name('sales-orders.show');
        Route::get('sales-orders/{sales_order}/edit', [SalesOrderController::class, 'edit'])->middleware('can:sales_orders.update')->name('sales-orders.edit');
        Route::put('sales-orders/{sales_order}', [SalesOrderController::class, 'update'])->middleware('can:sales_orders.update')->name('sales-orders.update');
        Route::post('sales-orders/{sales_order}/approve', [SalesOrderController::class, 'approve'])->middleware('can:sales_orders.approve')->name('sales-orders.approve');
        Route::post('sales-orders/{sales_order}/void', [SalesOrderController::class, 'void'])->middleware('can:sales_orders.void')->name('sales-orders.void');
        Route::post('sales-orders/{sales_order}/close', [SalesOrderController::class, 'close'])->middleware('can:sales_orders.update')->name('sales-orders.close');

        Route::get('delivery-orders', [DeliveryOrderController::class, 'index'])->middleware('can:delivery_orders.view')->name('delivery-orders.index');
        Route::get('delivery-orders/create', [DeliveryOrderController::class, 'create'])->middleware('can:delivery_orders.create')->name('delivery-orders.create');
        Route::post('delivery-orders', [DeliveryOrderController::class, 'store'])->middleware('can:delivery_orders.create')->name('delivery-orders.store');
        Route::get('delivery-orders/{delivery_order}', [DeliveryOrderController::class, 'show'])->middleware('can:delivery_orders.view')->name('delivery-orders.show');
        Route::get('delivery-orders/{delivery_order}/edit', [DeliveryOrderController::class, 'edit'])->middleware('can:delivery_orders.update')->name('delivery-orders.edit');
        Route::put('delivery-orders/{delivery_order}', [DeliveryOrderController::class, 'update'])->middleware('can:delivery_orders.update')->name('delivery-orders.update');
        Route::post('delivery-orders/{delivery_order}/post', [DeliveryOrderController::class, 'post'])->middleware('can:delivery_orders.post')->name('delivery-orders.post');
        Route::post('delivery-orders/{delivery_order}/void', [DeliveryOrderController::class, 'void'])->middleware('can:delivery_orders.void')->name('delivery-orders.void');
    });

    // Accounting module routes
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('users', [AdminUserController::class, 'index'])->middleware('can:users.view')->name('users.index');
        Route::get('users/create', [AdminUserController::class, 'create'])->middleware('can:users.create')->name('users.create');
        Route::post('users', [AdminUserController::class, 'store'])->middleware('can:users.create')->name('users.store');
        Route::get('users/{user}', [AdminUserController::class, 'show'])->middleware('can:users.view')->name('users.show');
        Route::get('users/{user}/edit', [AdminUserController::class, 'edit'])->middleware('can:users.update')->name('users.edit');
        Route::put('users/{user}', [AdminUserController::class, 'update'])->middleware('can:users.update')->name('users.update');
        Route::post('users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->middleware('can:users.update')->name('users.reset-password');
        Route::post('users/{user}/active', [AdminUserController::class, 'setActive'])->middleware('can:users.delete')->name('users.set-active');

        Route::get('roles', [AdminRoleController::class, 'index'])->middleware('can:roles.view')->name('roles.index');
        Route::get('roles/create', [AdminRoleController::class, 'create'])->middleware('can:roles.create')->name('roles.create');
        Route::post('roles', [AdminRoleController::class, 'store'])->middleware('can:roles.create')->name('roles.store');
        Route::get('roles/{role}', [AdminRoleController::class, 'show'])->middleware('can:roles.view')->name('roles.show');
        Route::get('roles/{role}/edit', [AdminRoleController::class, 'edit'])->middleware('can:roles.update')->name('roles.edit');
        Route::put('roles/{role}', [AdminRoleController::class, 'update'])->middleware('can:roles.update')->name('roles.update');
        Route::delete('roles/{role}', [AdminRoleController::class, 'destroy'])->middleware('can:roles.delete')->name('roles.destroy');
        Route::post('roles/{role}/duplicate', [AdminRoleController::class, 'duplicate'])->middleware('can:roles.create')->name('roles.duplicate');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit_logs.view')->name('audit-logs.index');
    });

    Route::prefix('accounting')->name('accounting.')->group(function (): void {
        Route::get('journals', [JournalController::class, 'index'])->middleware('can:journals.view')->name('journals.index');
        Route::get('journals/create', [JournalController::class, 'create'])->middleware('can:journals.create')->name('journals.create');
        Route::post('journals', [JournalController::class, 'store'])->middleware('can:journals.create')->name('journals.store');
        Route::get('journals/{journal}', [JournalController::class, 'show'])->middleware('can:journals.view')->name('journals.show');
        Route::get('journals/{journal}/edit', [JournalController::class, 'edit'])->middleware('can:journals.update')->name('journals.edit');
        Route::put('journals/{journal}', [JournalController::class, 'update'])->middleware('can:journals.update')->name('journals.update');
        Route::post('journals/{journal}/post', [JournalController::class, 'post'])->middleware('can:journals.post')->name('journals.post');
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->middleware('can:journals.reverse')->name('journals.reverse');
        Route::post('journals/{journal}/void', [JournalController::class, 'void'])->middleware('can:journals.void')->name('journals.void');

        Route::get('accounts', [AccountController::class, 'index'])->middleware('can:chart_of_accounts.view')->name('accounts.index');
        Route::get('accounts/create', [AccountController::class, 'create'])->middleware('can:chart_of_accounts.create')->name('accounts.create');
        Route::post('accounts', [AccountController::class, 'store'])->middleware('can:chart_of_accounts.create')->name('accounts.store');
        Route::get('accounts/{account}', [AccountController::class, 'show'])->middleware('can:chart_of_accounts.view')->name('accounts.show');
        Route::get('accounts/{account}/edit', [AccountController::class, 'edit'])->middleware('can:chart_of_accounts.update')->name('accounts.edit');
        Route::put('accounts/{account}', [AccountController::class, 'update'])->middleware('can:chart_of_accounts.update')->name('accounts.update');
        Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->middleware('can:chart_of_accounts.delete')->name('accounts.destroy');
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
