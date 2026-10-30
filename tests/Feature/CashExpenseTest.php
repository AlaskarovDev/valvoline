<?php

namespace Tests\Feature;

use App\Models\FinanceDocument;
use App\Models\User;
use App\Reports\ReportFilters;
use App\Services\CashExpenseService;
use App\Services\FinanceService;
use App\Services\ProfitLossService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kassa məxarici xərcə düşür; avans kassadan çıxır, avans hesabatı xərci yazır və qalığı kassaya qaytarır.
 */
class CashExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_expense_advance_and_report(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $driver = User::factory()->create(['role' => 'kassir', 'name' => 'Sürücü Elçin']);
        $register = (int) DB::table('cash_registers')->where('code', 'KS-001')->value('id');
        $workplace = (int) DB::table('workplaces')->value('id');
        $fuel = DB::table('expense_categories')->insertGetId(['name' => 'Yanacaq', 'op_type' => 'Xərc', 'affects_profit' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('finance.documents.store'), ['type' => 'cash_opening', 'date' => now()->subDay()->toDateString(), 'workplace_id' => $workplace, 'cash_register_id' => $register, 'amount' => 500]);
        $base = ['date' => now()->toDateString(), 'workplace_id' => $workplace, 'cash_register_id' => $register];
        $cash = fn () => (float) app(FinanceService::class)->cashLedger($register)->last()->balance;
        $pnl = fn () => (float) ProfitLossService::profitCash(new ReportFilters(now()->startOfMonth(), now()->endOfMonth()), 'expense')->sum('c.expense');

        // kassa məxarici: kassadan çıxır, xərc kimi mənfəət-zərərə düşür
        $this->get(route('cash-expenses.create'))->assertOk()->assertSee('Yanacaq');
        $this->post(route('cash-expenses.store'), $base + ['type' => 'cash_expense', 'amount' => 900, 'expense_category_id' => $fuel])->assertSessionHasErrors('amount');
        $this->post(route('cash-expenses.store'), $base + ['type' => 'cash_expense', 'amount' => 120, 'expense_category_id' => $fuel, 'note' => 'AZS'])
            ->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'KX-000001');
        $this->assertEquals([380, 120], [$cash(), $pnl()]);

        // avans: kassadan çıxır, hələ xərc deyil
        $this->post(route('cash-expenses.store'), $base + ['type' => 'cash_advance', 'amount' => 200, 'employee_id' => $driver->id])
            ->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'AV-000001');
        $this->assertEquals([180, 120], [$cash(), $pnl()]);
        $this->get(route('cash-expenses.advances'))->assertOk()->assertSee('Sürücü Elçin')->assertSee('200,00', false);

        // hesabat: 150 xərc + 60 qaytarma = 210 > 200 avans → rədd; 50 qaytarma ilə bağlanır
        $report = $base + ['type' => 'advance_report', 'employee_id' => $driver->id, 'lines' => [['expense_category_id' => $fuel, 'amount' => 150, 'description' => 'Bakı–Gəncə']]];
        $this->post(route('cash-expenses.store'), $report + ['returned' => 60])->assertSessionHasErrors('amount');
        $this->post(route('cash-expenses.store'), $report + ['returned' => 50])->assertSessionHas('popup', fn ($p) => $p['docs'][0]['no'] === 'AH-000001');
        $this->assertEquals([230, 270], [$cash(), $pnl()]);
        $this->assertSame(0.0, app(CashExpenseService::class)->balances()[$driver->id]['open']);
        $doc = FinanceDocument::query()->where('type', 'advance_report')->sole();
        $this->get(route('finance.documents.show', $doc))->assertOk()->assertSee('Bakı–Gəncə')->assertSee('Sürücü Elçin');
        $this->get(route('finance.register', $register))->assertOk()->assertSee('Avans qaytarıldı');

        // ləğv: xərc mənfəət-zərərdən çıxır, pul kassaya qayıdır
        $expense = FinanceDocument::query()->where('type', 'cash_expense')->sole();
        $this->delete(route('finance.documents.destroy', $expense), ['reason' => 'səhv'])->assertSessionHas('popup');
        $this->assertEquals([350, 150], [$cash(), $pnl()]);
        // ümumi forma bu növləri öz formasına yönləndirir
        $this->get(route('finance.documents.create', ['type' => 'cash_advance']))->assertRedirect(route('cash-expenses.create', ['type' => 'cash_advance']));
    }
}
