<?php

use App\Http\Controllers\AliasController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ProductCostController;
use App\Http\Controllers\Reports\CashController;
use App\Http\Controllers\Reports\CustomerController;
use App\Http\Controllers\Reports\DashboardController;
use App\Http\Controllers\Reports\DataQualityController;
use App\Http\Controllers\Reports\DebtController;
use App\Http\Controllers\Reports\ExpenseController;
use App\Http\Controllers\Reports\MarginController;
use App\Http\Controllers\Reports\MovementController;
use App\Http\Controllers\Reports\OrderController;
use App\Http\Controllers\Reports\PaymentController;
use App\Http\Controllers\Reports\ProfitLossController;
use App\Http\Controllers\Reports\PurchaseController;
use App\Http\Controllers\Reports\ReturnController;
use App\Http\Controllers\Reports\SalesController;
use App\Http\Controllers\Reports\StockController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/sifarisler', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/sifarisler/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/satis', SalesController::class)->name('sales.index');
    Route::get('/marjinalliq', MarginController::class)->name('margin.index');
    Route::get('/qaytarmalar', ReturnController::class)->name('returns.index');
    Route::get('/musteriler', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/musteriler/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('/borclar', DebtController::class)->name('debts.index');
    Route::get('/odenisler', PaymentController::class)->name('payments.index');
    Route::get('/anbar', StockController::class)->name('stock.index');
    Route::get('/anbar-hereketleri', [MovementController::class, 'index'])->name('movements.index');
    Route::get('/anbar-hereketleri/mal/{product}', [MovementController::class, 'product'])->name('movements.product');

    Route::middleware('can:admin')->group(function () {
        Route::get('/idxal', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::get('/idxal/{batch}', [PurchaseController::class, 'show'])->name('purchases.show');
        Route::get('/xercler', ExpenseController::class)->name('expenses.index');
        Route::get('/menfeet-zerer', ProfitLossController::class)->name('pnl.index');
        Route::get('/kassa', CashController::class)->name('cash.index');
        Route::get('/melumat-keyfiyyeti', DataQualityController::class)->name('quality.index');

        Route::get('/import', [ImportController::class, 'index'])->name('import.index');
        Route::post('/import', [ImportController::class, 'upload'])->name('import.upload');
        Route::get('/import/{batch}/onizleme', [ImportController::class, 'preview'])->name('import.preview');
        Route::post('/import/{batch}/tesdiq', [ImportController::class, 'confirm'])->name('import.confirm');
        Route::post('/import/{batch}/legv', [ImportController::class, 'cancel'])->name('import.cancel');
        Route::post('/import/{batch}/geri-qaytar', [ImportController::class, 'rollback'])->name('import.rollback');
        Route::post('/import/yeniden-hesabla', [ImportController::class, 'rebuild'])->name('import.rebuild');
        Route::get('/import/status', [ImportController::class, 'status'])->name('import.status');

        Route::get('/uygunlasdirma', [AliasController::class, 'index'])->name('aliases.index');
        Route::delete('/uygunlasdirma/{alias}', [AliasController::class, 'destroy'])->name('aliases.destroy');

        Route::get('/xerc-kateqoriyalari', [ExpenseCategoryController::class, 'index'])->name('categories.index');
        Route::put('/xerc-kateqoriyalari/{category}', [ExpenseCategoryController::class, 'update'])->name('categories.update');

        Route::put('/mallar/{product}/maya', [ProductCostController::class, 'update'])->name('products.cost');

        Route::get('/istifadeciler', [UserController::class, 'index'])->name('users.index');
        Route::post('/istifadeciler', [UserController::class, 'store'])->name('users.store');
        Route::put('/istifadeciler/{user}', [UserController::class, 'update'])->name('users.update');
    });
});
