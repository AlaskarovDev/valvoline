<?php

namespace App\Http\Controllers;

use App\Mail\DailyDigest;
use App\Services\DigestService;
use App\Services\TwoFactorService;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

/**
 * İstifadəçinin öz hesabı: iki mərhələli giriş və gündəlik e-mail xülasəsi.
 */
class AccountController extends Controller
{
    public function index(Request $request, TwoFactorService $twoFactor): View
    {
        $user = $request->user();
        $setup = null;
        if (! $twoFactor->enabled($user)) {
            // qoşulma açarı təsdiqə qədər sessiyada qalır
            $secret = $request->session()->get('2fa.setup') ?? Totp::generateSecret();
            $request->session()->put('2fa.setup', $secret);
            $uri = Totp::uri(config('app.name'), $user->email, $secret);
            $setup = ['secret' => trim(chunk_split($secret, 4, ' ')), 'uri' => $uri, 'qr' => $twoFactor->qrSvg($uri)];
        }

        return view('account.index', [
            'title' => __('Hesabım'), 'user' => $user, 'enabled' => $twoFactor->enabled($user), 'required' => $twoFactor->isRequired($user), 'setup' => $setup,
            'recoveryCodes' => session('2fa.codes'), 'recoveryLeft' => count((array) $user->two_factor_recovery_codes),
            'mailReady' => config('mail.default') !== 'log' && config('mail.default') !== 'array',
        ]);
    }

    public function confirm(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $data = $request->validate(['otp' => ['required', 'string', 'max:10']]);
        $secret = $request->session()->get('2fa.setup');
        $codes = $secret ? $twoFactor->confirm($request->user(), $secret, $data['otp']) : null;
        if ($codes === null) {
            return back()->withErrors(['otp' => __('Kod yanlışdır. Telefondakı saatın düzgün olduğunu yoxlayın və yeni kodu yazın.')]);
        }
        $request->session()->forget('2fa.setup');

        return redirect()->route('account.index')->with('2fa.codes', $codes)
            ->with('status', __('İki mərhələli giriş qoşuldu. Ehtiyat kodları təhlükəsiz yerdə saxlayın.'));
    }

    public function recoveryCodes(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);

        return redirect()->route('account.index')->with('2fa.codes', $twoFactor->regenerateRecoveryCodes($request->user()))
            ->with('status', __('Yeni ehtiyat kodlar yaradıldı — köhnələr artıq işləmir.'));
    }

    public function disable(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        if ($twoFactor->isRequired($request->user())) {
            return back()->with('error', __('Sizin rolunuz üçün iki mərhələli giriş məcburidir — söndürmək olmaz.'));
        }
        $twoFactor->disable($request->user());

        return redirect()->route('account.index')->with('status', __('İki mərhələli giriş söndürüldü.'));
    }

    public function digest(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['digest_enabled' => $request->boolean('digest_enabled')])->save();

        return back()->with('status', $request->boolean('digest_enabled') ? __('Gündəlik xülasə hər səhər e-poçtunuza göndəriləcək.') : __('Gündəlik xülasə söndürüldü.'));
    }

    /**
     * Xülasəni indi özünə göndər — SMTP ayarını yoxlamaq üçün.
     */
    public function testDigest(Request $request, DigestService $digests): RedirectResponse
    {
        try {
            Mail::to($request->user())->send(new DailyDigest($digests->build($request->user())));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('Məktub göndərilmədi: :error', ['error' => mb_substr($e->getMessage(), 0, 200)]));
        }

        return back()->with('status', __('Xülasə :email ünvanına göndərildi.', ['email' => $request->user()->email]));
    }
}
