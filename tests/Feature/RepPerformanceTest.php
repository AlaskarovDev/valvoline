<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RepPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan–fakt: hədəf (aylıq və ya kartdakı), fakt = satış − qaytarma, pilləli bonus; qaydalar dəyişdirilə bilir.
 */
class RepPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_fact_and_bonus_tiers(): void
    {
        $period = now()->format('Y-m');
        $day = now()->startOfMonth()->toDateString();
        $rasim = DB::table('managers')->insertGetId(['name' => 'Rasim', 'code' => 'SN-1', 'monthly_target' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        $elnur = DB::table('managers')->insertGetId(['name' => 'Elnur', 'code' => 'SN-2', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rep_targets')->insert(['manager_id' => $elnur, 'period' => $period, 'sales_target' => 2000, 'collection_target' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $line = fn (int $manager, float $net, float $profit) => DB::table('sales_lines')->insert(['row_no' => 1, 'date' => $day, 'period' => $period, 'order_no' => 'S'.$manager.$net,
            'manager_id' => $manager, 'product_code' => 'OIL', 'qty' => 1, 'net_amount' => $net, 'gross_profit' => $profit, 'op_type' => 'sale', 'created_at' => now(), 'updated_at' => now()]);
        $line($rasim, 1100, 200);
        $line($elnur, 1500, 300);
        DB::table('sales_returns')->insert(['row_no' => 1, 'date' => $day, 'period' => $period, 'manager_id' => $rasim, 'product_code' => 'OIL', 'qty' => 1, 'net_amount' => 100,
            'op_type' => 'customer', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['row_no' => 1, 'date' => $day, 'period' => $period, 'manager_id' => $elnur, 'cash' => 600, 'bank' => 0, 'total' => 600, 'created_at' => now(), 'updated_at' => now()]);

        // standart qaydalar: satış icrası, bazası satış; 80 → 0,5 %, 100 → 1 %, 120 → 1,5 %
        $rows = app(RepPerformanceService::class)->month($period)->keyBy('name');
        $this->assertEquals([1000, 1000, 100, 1.0, 10], [$rows['Rasim']->sales_target, $rows['Rasim']->sales, $rows['Rasim']->sales_pct, $rows['Rasim']->bonus_rate, $rows['Rasim']->bonus]);
        $this->assertEquals([75, 0, 120], [$rows['Elnur']->sales_pct, $rows['Elnur']->bonus, $rows['Elnur']->collection_pct]);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('reps.performance'))->assertOk()->assertSee('Rasim')->assertSee('Elnur')->assertSee('10,00', false);
        // inkasso icrası, bazası brüt mənfəət: Elnur 120 % ≥ 100 → mənfəətin 5 %-i = 15
        $this->post(route('reps.bonus-rules'), ['measure' => 'collections', 'base' => 'profit', 'tiers' => [['from' => 100, 'rate' => 5]]])->assertSessionHas('status');
        $rows = app(RepPerformanceService::class)->month($period)->keyBy('name');
        $this->assertEquals([15, 0], [$rows['Elnur']->bonus, $rows['Rasim']->bonus]);
        // aylıq hədəf kartdakını əvəz edir
        $this->post(route('reps.targets'), ['period' => $period, 'targets' => [$rasim => ['sales' => 2000, 'collections' => null]]])->assertSessionHas('status');
        $this->assertEquals(50, app(RepPerformanceService::class)->month($period)->firstWhere('name', 'Rasim')->sales_pct);

        // nümayəndə yalnız özünü görür, hədəf dəyişə bilmir
        $rep = User::factory()->create(['role' => 'menecer', 'manager_id' => $elnur]);
        $this->actingAs($rep)->get(route('reps.performance'))->assertOk()->assertSee('Elnur')->assertDontSee('Rasim');
        $this->post(route('reps.targets'), ['period' => $period, 'targets' => []])->assertForbidden();
    }
}
