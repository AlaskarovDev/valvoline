<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AliasController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProductCostController;
use App\Http\Controllers\Reports\CashController;
use App\Http\Controllers\Reports\CustomerController;
use App\Http\Controllers\Reports\DashboardController;
use App\Http\Controllers\Reports\DataQualityController;
use App\Http\Controllers\Reports\DebtController;
use App\Http\Controllers\Reports\ExpenseController;
use App\Http\Controllers\Reports\ExpenseDetailController;
use App\Http\Controllers\Reports\MarginController;
use App\Http\Controllers\Reports\MovementController;
use App\Http\Controllers\Reports\OrderController;
use App\Http\Controllers\Reports\PaymentController;
use App\Http\Controllers\Reports\ProfitLossController;
use App\Http\Controllers\Reports\ProfitLossDetailController;
use App\Http\Controllers\Reports\PurchaseController;
use App\Http\Controllers\Reports\ReturnController;
use App\Http\Controllers\Reports\SalesController;
use App\Http\Controllers\Reports\StockController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/dil/{locale}', LocaleController::class)->name('locale');

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
        Route::get('/xercler/detal', ExpenseDetailController::class)->name('expenses.detail');
        Route::get('/menfeet-zerer', ProfitLossController::class)->name('pnl.index');
        Route::get('/pul-axini', ProfitLossController::class)->name('flowsheet.index');
        Route::get('/menfeet-zerer/detal', ProfitLossDetailController::class)->name('pnl.detail');
        Route::post('/menfeet-zerer/qaliqlar', [ProfitLossController::class, 'saveOpenings'])->name('pnl.openings');
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

        Route::get('/muhasibat/hesablar-plani', [AccountingController::class, 'chart'])->name('accounting.chart');
        Route::post('/muhasibat/hesablar-plani', [AccountingController::class, 'storeAccount'])->name('accounting.chart.store');
        Route::get('/muhasibat/hesablar-plani/{code}/menbe', [AccountingController::class, 'accountSource'])->name('accounting.chart.source');
        Route::get('/muhasibat/hesablar-plani/{code}/idareetme', [AccountingController::class, 'managementSource'])->name('accounting.chart.mgmt');
        Route::put('/muhasibat/hesablar-plani/{account}', [AccountingController::class, 'updateAccount'])->name('accounting.chart.update');
        Route::get('/muhasibat/tutusdurma', [AccountingController::class, 'reconcile'])->name('accounting.reconcile');
        Route::post('/muhasibat/tutusdurma/gelir', [AccountingController::class, 'decideRevenue'])->name('accounting.revenue.decide');
        Route::get('/muhasibat/iller', [AccountingController::class, 'overview'])->name('accounting.overview');
        Route::get('/muhasibat/balans', [AccountingController::class, 'balance'])->name('accounting.balance');
        Route::get('/muhasibat/hesab/{code}', [AccountingController::class, 'ledger'])->name('accounting.ledger');
        Route::get('/muhasibat/menfeet-zerer', [AccountingController::class, 'profitLoss'])->name('accounting.pl');
        Route::get('/muhasibat/cash-flow', [AccountingController::class, 'cashFlow'])->name('accounting.cashflow');
        Route::post('/muhasibat/cash-flow/tesnifat', [AccountingController::class, 'classify'])->name('accounting.classify');

        Route::get('/bank-hesablari', [BankAccountController::class, 'index'])->name('bank.index');
        Route::post('/bank-hesablari', [BankAccountController::class, 'store'])->name('bank.store');
        Route::delete('/bank-hesablari/emeliyyat/{transaction}', [BankAccountController::class, 'destroyTransaction'])->name('bank.tx.destroy');
        Route::get('/bank-hesablari/{account}', [BankAccountController::class, 'show'])->name('bank.show');
        Route::put('/bank-hesablari/{account}', [BankAccountController::class, 'update'])->name('bank.update');
        Route::post('/bank-hesablari/{account}/emeliyyat', [BankAccountController::class, 'storeTransaction'])->name('bank.tx.store');
        Route::post('/bank-hesablari/{account}/cixaris', [BankAccountController::class, 'import'])->name('bank.import');

        Route::get('/istifadeciler', [UserController::class, 'index'])->name('users.index');
        Route::post('/istifadeciler', [UserController::class, 'store'])->name('users.store');
        Route::put('/istifadeciler/{user}', [UserController::class, 'update'])->name('users.update');
    });
});
