<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Parol düzgündürsə, 2FA kodu bu müddət ərzində daxil edilməlidir (saniyə). */
    private const CHALLENGE_TTL = 300;

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $provider = Auth::getProvider();
        $user = $provider->retrieveByCredentials(['email' => $credentials['email'], 'is_active' => true]);
        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            return back()->withErrors(['email' => __('E-poçt və ya parol yanlışdır.')])->onlyInput('email');
        }
        // 2FA qoşulubsa: parol yoxlanıb, giriş kod təsdiqlənəndə tamamlanır
        if ($twoFactor->enabled($user)) {
            $request->session()->put('login.2fa', ['id' => $user->id, 'remember' => $request->boolean('remember'), 'at' => now()->getTimestamp()]);

            return redirect()->route('two-factor.challenge');
        }
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        return $this->pending($request) ? view('auth.two-factor') : redirect()->route('login');
    }

    public function verify(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $user = $this->pending($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => __('Kodun vaxtı bitdi — yenidən daxil olun.')]);
        }
        $data = $request->validate(['otp' => ['nullable', 'string', 'max:10'], 'recovery_code' => ['nullable', 'string', 'max:20']]);
        if (! $twoFactor->verify($user, $data['otp'] ?? null, $data['recovery_code'] ?? null)) {
            return back()->withErrors(['otp' => __('Kod yanlışdır və ya vaxtı keçib.')]);
        }
        $remember = (bool) $request->session()->pull('login.2fa')['remember'];
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function pending(Request $request): ?User
    {
        $pending = $request->session()->get('login.2fa');
        if (! is_array($pending) || now()->getTimestamp() - (int) $pending['at'] > self::CHALLENGE_TTL) {
            $request->session()->forget('login.2fa');

            return null;
        }

        return User::query()->where('is_active', true)->find($pending['id']);
    }
}
