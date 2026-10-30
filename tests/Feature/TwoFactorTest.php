<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\AppSettings;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * İki mərhələli giriş: TOTP (RFC 6238), qoşma, girişdə kod / ehtiyat kod, təkrar kodun rədd edilməsi, məcburi rollar.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_matches_rfc_6238_vector(): void
    {
        // RFC 6238 əlavəsi: açar "12345678901234567890", T = 59 s → 94287082 (son 6 rəqəm)
        $this->assertSame('287082', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::step(59)));
        $this->assertSame(1, Totp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287 082', null, 59));
        $this->assertNull(Totp::verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 1, 59), 'işlənmiş addım təkrar qəbul olunmur');
        $this->assertSame(32, strlen(Totp::generateSecret()));
    }

    public function test_enable_login_with_code_and_recovery_code(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'email' => 'boss@valvo.az', 'password' => 'secret-password']);
        $this->actingAs($user)->get(route('account.index'))->assertOk()->assertSee('<svg', false)->assertSee('Qoşulmayıb');
        $secret = session('2fa.setup');
        $this->post(route('account.2fa.confirm'), ['otp' => '000000'])->assertSessionHasErrors('otp');
        $this->post(route('account.2fa.confirm'), ['otp' => Totp::code($secret, Totp::step())])->assertSessionHas('2fa.codes');
        $user->refresh();
        $codes = $user->two_factor_recovery_codes;
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertCount(8, $codes);
        $this->assertStringNotContainsString($secret, (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret'), 'açar şifrələnib');
        $this->assertStringNotContainsString($secret, (string) DB::table('audit_logs')->pluck('payload')->implode(' '));

        // parol düzgündür, amma kod olmadan giriş tamamlanmır
        $this->post(route('logout'));
        $this->post('/login', ['email' => 'boss@valvo.az', 'password' => 'secret-password'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('two-factor.challenge'))->assertOk()->assertSee('Təsdiq kodu');
        // qoşulma zamanı işlənmiş kod təkrar keçmir
        $this->post(route('two-factor.verify'), ['otp' => Totp::code($secret, Totp::step())])->assertSessionHasErrors('otp');
        $this->assertGuest();
        $this->travel(31)->seconds();
        $this->post(route('two-factor.verify'), ['otp' => Totp::code($secret, Totp::step())])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // ehtiyat kod bir dəfə işləyir
        $this->post(route('logout'));
        $this->post('/login', ['email' => 'boss@valvo.az', 'password' => 'secret-password']);
        $this->post(route('two-factor.verify'), ['recovery_code' => strtoupper($codes[0])])->assertRedirect(route('dashboard'));
        $this->assertCount(7, $user->fresh()->two_factor_recovery_codes);
        $this->post(route('logout'));
        $this->post('/login', ['email' => 'boss@valvo.az', 'password' => 'secret-password']);
        $this->post(route('two-factor.verify'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('otp');
        $this->assertGuest();

        // kobud güc: dəqiqədə 5 cəhd
        foreach (range(1, 4) as $i) {
            $this->post(route('two-factor.verify'), ['otp' => '000000']);
        }
        $this->post(route('two-factor.verify'), ['otp' => '000000'])->assertStatus(429);

        // kod səhifəsinin vaxtı keçir
        $this->travel(6)->minutes();
        $this->post(route('two-factor.verify'), ['recovery_code' => $codes[1]])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_required_roles_force_enrolment_and_admin_can_reset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->put(route('users.2fa.roles'), ['roles' => ['muhasib']])->assertSessionHas('status');
        $this->assertSame(['muhasib'], TwoFactorService::requiredRoles());
        $this->assertSame('["muhasib"]', AppSettings::get(TwoFactorService::SETTING));

        $accountant = User::factory()->create(['role' => 'muhasib']);
        $this->actingAs($accountant)->get(route('dashboard'))->assertRedirect(route('account.index'));
        $this->get(route('account.index'))->assertOk()->assertSee('Məcburidir');
        $secret = session('2fa.setup');
        $this->post(route('account.2fa.confirm'), ['otp' => Totp::code($secret, Totp::step())]);
        $this->get(route('dashboard'))->assertOk();
        // məcburi rolda söndürmək olmaz
        $this->delete(route('account.2fa.disable'), ['current_password' => 'password'])->assertSessionHas('error');

        // telefonu itirdi — admin sıfırlayır
        $this->actingAs($admin)->get(route('users.index'))->assertSee('2FA')->assertSee(route('users.2fa.reset', $accountant), false);
        $this->delete(route('users.2fa.reset', $accountant))->assertSessionHas('status');
        $this->assertNull($accountant->fresh()->two_factor_confirmed_at);
    }
}
