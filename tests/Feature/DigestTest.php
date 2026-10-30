<?php

namespace Tests\Feature;

use App\Mail\DailyDigest;
use App\Models\User;
use App\Services\DigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Gündəlik e-mail xülasəsi: abunə, 08:00-dan sonra gündə bir dəfə, istifadəçinin icazəsinə görə məzmun.
 */
class DigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_is_sent_once_a_day_after_eight_to_subscribers(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-10-20 03:30:00', 'UTC')); // 07:30 Bakı
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'boss@valvo.az']);
        $keeper = User::factory()->create(['role' => 'anbardar', 'email' => 'anbar@valvo.az']);
        User::factory()->create(['role' => 'admin', 'email' => 'off@valvo.az']);
        DB::table('sales_lines')->insert(['row_no' => 1, 'date' => '2026-10-19', 'period' => '2026-10', 'order_no' => 'S1', 'product_code' => 'OIL', 'qty' => 1,
            'net_amount' => 1234.5, 'op_type' => 'sale', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->post(route('account.digest'), ['digest_enabled' => 1])->assertSessionHas('status');
        $this->actingAs($keeper)->post(route('account.digest'), ['digest_enabled' => 1]);
        $this->assertSame(['sent' => 0, 'failed' => 0], app(DigestService::class)->sendDue(), '08:00-dan əvvəl göndərilmir');

        $this->travelTo(Carbon::parse('2026-10-20 05:00:00', 'UTC')); // 09:00 Bakı
        $this->artisan('digest:send')->assertSuccessful();
        Mail::assertSent(DailyDigest::class, 2);
        Mail::assertSent(DailyDigest::class, fn ($m) => $m->hasTo('boss@valvo.az')
            && collect($m->digest['kpis'])->contains(fn ($k) => $k['label'] === 'Dünənki satış' && preg_replace('/\D/', '', $k['value']) === '123450'));
        // anbardar satış rəqəmlərini görmür
        Mail::assertSent(DailyDigest::class, fn ($m) => $m->hasTo('anbar@valvo.az') && $m->digest['kpis'] === []);
        Mail::assertNotSent(DailyDigest::class, fn ($m) => $m->hasTo('off@valvo.az'));
        $this->assertSame('2026-10-20', substr((string) $admin->fresh()->digest_sent_on, 0, 10));

        $this->assertSame(['sent' => 0, 'failed' => 0], app(DigestService::class)->sendDue(), 'eyni gün ikinci dəfə göndərilmir');

        $html = (new DailyDigest(app(DigestService::class)->build($admin)))->render();
        $this->assertStringContainsString('Dünənki satış', $html);
        $this->assertStringContainsString('19.10.2026', $html);

        $this->actingAs($admin)->get(route('account.index'))->assertSee('Gündəlik e-mail xülasəsi')->assertSee('Açıqdır');
        $this->post(route('account.digest.test'))->assertSessionHas('status');
        Mail::assertSent(DailyDigest::class, 3);
    }
}
