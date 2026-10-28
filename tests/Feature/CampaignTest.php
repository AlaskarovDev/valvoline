<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CampaignEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private int $managerId;

    private int $oil;

    private int $filter;

    private int $gift;

    protected function setUp(): void
    {
        parent::setUp();
        $now = now();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->managerId = DB::table('managers')->insertGetId(['name' => 'Əkbər', 'created_at' => $now, 'updated_at' => $now]);
        $this->customer = Customer::query()->create(['code' => 'C-001', 'name' => 'Turabov Rəsul', 'manager_id' => $this->managerId]);
        $group = DB::table('product_groups')->insertGetId(['name' => 'MaxLife', 'created_at' => $now, 'updated_at' => $now]);
        $this->oil = DB::table('products')->insertGetId(['code' => 'VE11258', 'name' => 'MaxLife 5W-40 4L', 'sale_price' => 50, 'product_group_id' => $group, 'series' => 'MAXLIFE', 'created_at' => $now, 'updated_at' => $now]);
        $this->filter = DB::table('products')->insertGetId(['code' => 'FL-1', 'name' => 'Filtr', 'sale_price' => 10, 'created_at' => $now, 'updated_at' => $now]);
        $this->gift = DB::table('products')->insertGetId(['code' => 'GIFT-1', 'name' => 'Brelok', 'sale_price' => 0, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function campaign(array $attributes): Campaign
    {
        static $n = 0;
        $n++;

        return Campaign::query()->create(array_merge([
            'code' => 'KMP.T.'.$n, 'name' => 'Kampaniya '.$n, 'kind' => 'simple_discount', 'discount_rate' => 10,
            'begin_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'approved', 'is_active' => true,
            'product_scope' => 'all', 'customer_scope' => 'all',
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(array $lines, array $excluded = []): array
    {
        return app(CampaignEngine::class)->evaluate($this->customer, Carbon::parse('2026-03-05'), $lines, $excluded);
    }

    private function line(int $product, float $qty, float $price, float $discount = 0): array
    {
        return ['product_id' => $product, 'op_type' => 'sale', 'qty' => $qty, 'price' => $price, 'discount' => $discount];
    }

    public function test_discounts_chain_by_priority_on_remaining_amount(): void
    {
        $this->campaign(['code' => 'A', 'priority' => 1, 'discount_rate' => 10, 'product_scope' => 'products', 'product_filter' => [['id' => $this->oil, 'rate' => 20, 'price' => null]]]);
        $this->campaign(['code' => 'B', 'priority' => 2, 'kind' => 'invoice_bottom_discount', 'discount_rate' => 5, 'excluded_products' => [$this->filter]]);
        $result = $this->evaluate([$this->line($this->oil, 10, 50, 10), $this->line($this->filter, 2, 10)]);

        // 500 − 10 % əl ilə = 450; A: mala ayrıca 20 % → 90; B: qalan 360-ın 5 %-i → 18
        $this->assertSame([90.0, 18.0], array_column($result['lines'][0]['discounts'], 'amount'));
        $this->assertSame(342.0, $result['lines'][0]['net']);
        $this->assertSame([], $result['lines'][1]['discounts'], 'filtr A-nın malı deyil, B-dən xaricdir');
        $this->assertSame(108.0, $result['total_discount']);

        // brüt bazası (P1): 5 % × 500 = 25
        Campaign::query()->where('code', 'B')->update(['formula_base' => 'P1']);
        $this->assertSame(25.0, $this->evaluate([$this->line($this->oil, 10, 50, 10)])['lines'][0]['discounts'][1]['amount']);
    }

    public function test_priority_group_threshold_and_exclusion(): void
    {
        $this->campaign(['code' => 'G1', 'priority' => 1, 'priority_group' => 'YAY', 'kind' => 'qty_threshold_discount', 'threshold_value' => 20, 'discount_rate' => 15]);
        $this->campaign(['code' => 'G2', 'priority' => 2, 'priority_group' => 'YAY', 'discount_rate' => 5]);

        $small = $this->evaluate([$this->line($this->oil, 10, 50)]);
        $this->assertSame(['G2'], array_column($small['lines'][0]['discounts'], 'code'), 'G1 həddi ödənmir → qrupdan G2 tətbiq olunur');
        $big = $this->evaluate([$this->line($this->oil, 20, 50)]);
        $this->assertSame(['G1'], array_column($big['lines'][0]['discounts'], 'code'), 'eyni qrupdan yalnız biri');
        $this->assertStringContainsString('G1', collect($big['campaigns'])->firstWhere('code', 'G2')['reason']);

        $g1 = Campaign::query()->where('code', 'G1')->value('id');
        $this->assertSame(['G2'], array_column($this->evaluate([$this->line($this->oil, 20, 50)], [$g1])['lines'][0]['discounts'], 'code'));
    }

    public function test_tiered_cap_target_price_and_gifts(): void
    {
        $this->campaign(['code' => 'T', 'kind' => 'tiered_discount', 'calc_basis' => 'qty', 'tiers' => [['from' => 5, 'to' => 10, 'value' => 5], ['from' => 10, 'to' => null, 'value' => 8]], 'product_scope' => 'series', 'product_filter' => ['MAXLIFE']]);
        $this->assertSame(40.0, $this->evaluate([$this->line($this->oil, 10, 50)])['lines'][0]['campaign_discount'], '10 əd. → 8 %');
        $this->assertSame(0.0, $this->evaluate([$this->line($this->oil, 4, 50)])['lines'][0]['campaign_discount']);

        Campaign::query()->where('code', 'T')->update(['is_active' => false]);
        $this->campaign(['code' => 'CAP', 'kind' => 'max_discount_cap', 'discount_rate' => 12]);
        $this->assertSame(10.0, $this->evaluate([$this->line($this->oil, 2, 50, 2)])['lines'][0]['campaign_discount'], 'əl ilə 2 % + tavan 12 % qədər tamamlanır');

        Campaign::query()->where('code', 'CAP')->update(['is_active' => false]);
        $this->campaign(['code' => 'TP', 'kind' => 'target_price', 'product_scope' => 'products', 'product_filter' => [['id' => $this->oil, 'rate' => null, 'price' => 44]]]);
        $this->assertSame(18.0, $this->evaluate([$this->line($this->oil, 3, 50)])['lines'][0]['campaign_discount'], '3 × (50 − 44)');

        Campaign::query()->where('code', 'TP')->update(['is_active' => false]);
        $this->campaign(['code' => 'GF', 'kind' => 'qty_gift', 'gift_product_id' => $this->gift, 'gift_divisor' => 6, 'gift_qty' => 1, 'threshold_value' => 6, 'product_scope' => 'groups', 'product_filter' => [DB::table('product_groups')->value('id')]]);
        $gifts = $this->evaluate([$this->line($this->oil, 13, 50), $this->line($this->filter, 1, 10)])['gifts'];
        $this->assertSame([['GIFT-1', 2.0, 0]], array_map(fn ($g) => [$g['product_code'], $g['qty'], $g['line']], $gifts), 'hər 6-ya 1 → 13 əd. = 2 hədiyyə');
    }

    public function test_customer_scope_dates_status_and_limit(): void
    {
        $other = DB::table('managers')->insertGetId(['name' => 'Rasim', 'created_at' => now(), 'updated_at' => now()]);
        $c = $this->campaign(['customer_scope' => 'managers', 'customer_filter' => [$other]]);
        $this->assertSame(0.0, $this->evaluate([$this->line($this->oil, 1, 50)])['total_discount']);
        $c->update(['customer_filter' => [$this->managerId]]);
        $this->assertSame(5.0, $this->evaluate([$this->line($this->oil, 1, 50)])['total_discount']);
        $c->update(['customer_excludes' => [$this->customer->id]]);
        $this->assertSame(0.0, $this->evaluate([$this->line($this->oil, 1, 50)])['total_discount']);
        $c->update(['customer_excludes' => null, 'status' => 'pending']);
        $this->assertSame(0.0, $this->evaluate([$this->line($this->oil, 1, 50)])['total_discount'], 'təsdiqlənməyib');
        $c->update(['status' => 'approved', 'end_date' => '2026-03-01']);
        $this->assertSame(0.0, $this->evaluate([$this->line($this->oil, 1, 50)])['total_discount'], 'müddəti bitib');
        $c->update(['end_date' => '2026-12-31', 'apply_limit' => 1]);

        $this->actingAs($this->admin)->post(route('orders.store'), $this->orderPayload(['order_no' => '1']))->assertRedirect();
        $result = $this->evaluate([$this->line($this->oil, 1, 50)]);
        $this->assertSame(0.0, $result['total_discount'], 'müştəri üzrə 1 sifariş limiti dolub');
        $this->assertSame(__('Müştəri üzrə tətbiq limiti dolub'), $result['campaigns'][0]['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-03-05', 'order_no' => '5001', 'customer_id' => $this->customer->id, 'manager_id' => $this->managerId,
            'warehouse_id' => Warehouse::query()->value('id'), 'apply_campaigns' => 1,
            'lines' => [['product_id' => $this->oil, 'op_type' => 'sale', 'qty' => 12, 'price' => 50, 'discount' => 0]],
        ], $overrides);
    }

    public function test_order_with_campaign_stores_sub_lines_and_gift(): void
    {
        $discount = $this->campaign(['code' => 'YAY10', 'name' => 'Yay endirimi', 'discount_rate' => 10]);
        $this->campaign(['code' => 'HDY', 'kind' => 'qty_gift', 'gift_product_id' => $this->gift, 'gift_divisor' => 6, 'gift_qty' => 1]);

        $this->actingAs($this->admin)->postJson(route('orders.campaigns'), ['customer_id' => $this->customer->id, 'date' => '2026-03-05', 'lines' => [$this->line($this->oil, 12, 50)]])
            ->assertOk()->assertJsonPath('lines.0.discounts.0.code', 'YAY10')->assertJsonPath('lines.0.campaign_discount', 60)->assertJsonPath('gifts.0.qty', 2);

        $this->post(route('orders.store'), $this->orderPayload())->assertRedirect();
        $order = SalesOrder::query()->where('order_no', '5001')->firstOrFail();
        $this->assertTrue($order->campaigns_applied);
        $this->assertEqualsWithDelta(540.0, (float) $order->total_amount, 0.001);
        $line = DB::table('sales_lines')->where('sales_order_id', $order->id)->where('op_type', 'sale')->first();
        $this->assertEqualsWithDelta(60.0, (float) $line->campaign_discount, 0.001);
        $this->assertEqualsWithDelta(45.0, (float) $line->discount_price, 0.001);
        $this->assertSame(1, DB::table('sales_line_campaigns')->where('sales_line_id', $line->id)->where('campaign_id', $discount->id)->count());
        $gift = DB::table('sales_lines')->where('sales_order_id', $order->id)->where('op_type', 'gift')->first();
        $this->assertSame(['GIFT-1', 2.0, 0.0], [$gift->product_code, (float) $gift->qty, (float) $gift->net_amount]);

        $this->get(route('orders.show', $order))->assertOk()->assertSee('YAY10')->assertSee('Yay endirimi')->assertSee('HDY')->assertSee('Kampaniya hədiyyəsi');
        $this->get(route('orders.edit', $order))->assertOk()
            ->assertViewHas('values', fn ($v) => $v['apply_campaigns'] === true && count($v['lines']) === 1);
        $this->get(route('campaigns.show', $discount))->assertOk()->assertSee('5001')->assertSee('60,00', false);

        // kampaniya çıxarılıb yadda saxlanılır
        $this->put(route('orders.update', $order), $this->orderPayload(['excluded_campaigns' => [$discount->id]]))->assertRedirect();
        $this->assertSame(0, DB::table('sales_line_campaigns')->where('sales_order_id', $order->id)->count());
        $this->assertEqualsWithDelta(600.0, (float) $order->refresh()->total_amount, 0.001);
        $this->assertSame(1, DB::table('sales_lines')->where('sales_order_id', $order->id)->where('op_type', 'gift')->count());
        // kampaniya ləğv edilir
        $this->put(route('orders.update', $order), $this->orderPayload(['apply_campaigns' => 0]))->assertRedirect();
        $this->assertSame(0, DB::table('sales_lines')->where('sales_order_id', $order->id)->whereNotNull('campaign_id')->count());
    }

    public function test_campaign_crud_and_approval_flow(): void
    {
        $this->actingAs($this->admin)->get(route('campaigns.create'))->assertOk()->assertSee('Kampaniya növü və şərt');
        $payload = [
            'name' => 'Yaz kampaniyası', 'kind' => 'tiered_discount', 'calc_basis' => 'amount', 'tier_calc' => 'percent', 'tier_scope' => 'products',
            'tiers' => [['from' => 100, 'to' => 500, 'value' => 3], ['from' => 500, 'to' => '', 'value' => 6]],
            'formula_base' => 'P3', 'vat_multiplier' => 1, 'product_scope' => 'products', 'products' => [['id' => $this->oil]],
            'customer_scope' => 'customers', 'customers' => [$this->customer->id], 'begin_date' => '2026-03-01', 'end_date' => '2026-03-31', 'is_active' => 1,
        ];
        $this->post(route('campaigns.store'), $payload)->assertRedirect();
        $campaign = Campaign::query()->where('name', 'Yaz kampaniyası')->firstOrFail();
        $this->assertSame(['approved', 'KMP.202603.0001', 'amount'], [$campaign->status, $campaign->code, $campaign->calc_basis]);
        $this->assertEquals([['from' => 100, 'to' => 500, 'value' => 3], ['from' => 500, 'to' => null, 'value' => 6]], $campaign->tiers);
        $this->get(route('campaigns.index'))->assertOk()->assertSee('KMP.202603.0001')->assertSee('Pilləli endirim');
        $this->get(route('campaigns.show', $campaign))->assertOk()->assertSee('Turabov Rəsul')->assertSee('VE11258');

        // növə görə yoxlama
        $this->post(route('campaigns.store'), array_merge($payload, ['kind' => 'qty_gift', 'tiers' => []]))->assertSessionHasErrors(['gift_product_id', 'gift_qty']);
        $this->post(route('campaigns.store'), array_merge($payload, ['customers' => []]))->assertSessionHasErrors('customers');

        // menecer → təsdiq gözləyir → admin təsdiqləyir
        $manager = User::factory()->create(['role' => 'menecer', 'manager_id' => $this->managerId]);
        $this->actingAs($manager)->post(route('campaigns.store'), array_merge($payload, ['name' => 'Menecer təklifi']))->assertRedirect();
        $proposal = Campaign::query()->where('name', 'Menecer təklifi')->firstOrFail();
        $this->assertSame('pending', $proposal->status);
        $this->post(route('campaigns.decide', $proposal), ['action' => 'approve'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('campaigns.decide', $proposal), ['action' => 'reject'])->assertSessionHasErrors('reason');
        $this->post(route('campaigns.decide', $proposal), ['action' => 'approve'])->assertRedirect();
        $this->assertSame(['approved', $this->admin->id], [$proposal->refresh()->status, $proposal->approved_by]);

        $this->put(route('campaigns.update', $campaign), array_merge($payload, ['name' => 'Yaz kampaniyası 2']))->assertRedirect();
        $this->assertSame(['Yaz kampaniyası 2', 'KMP.202603.0001'], [$campaign->refresh()->name, $campaign->code]);
        $this->delete(route('campaigns.destroy', $campaign))->assertRedirect(route('campaigns.index'));
        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    }
}
