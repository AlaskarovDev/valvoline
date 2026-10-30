<x-layouts.app :title="$title">
    <section class="card">
        <div class="card-head"><div>
            <h2 class="card-title">{{ __('İki mərhələli giriş (2FA)') }}</h2>
            <div class="card-sub">{{ __('Girişdə paroldan sonra telefondakı authenticator tətbiqinin (Google Authenticator, Microsoft Authenticator) 6 rəqəmli kodu soruşulur. Parol oğurlansa belə, telefon olmadan hesaba girmək olmur.') }}</div>
        </div>
            @if ($enabled)<span class="badge badge-pos">{{ __('Qoşulub') }}</span>@else<span @class(['badge', 'badge-neg' => $required])>{{ $required ? __('Məcburidir') : __('Qoşulmayıb') }}</span>@endif
        </div>

        @if ($recoveryCodes)
            <div class="border-b border-line p-4">
                <div class="mb-2 text-sm font-semibold">{{ __('Ehtiyat kodlar — bir dəfə göstərilir') }}</div>
                <p class="mb-3 text-sm text-muted">{{ __('Telefonu itirsəniz, hər kodla bir dəfə daxil ola bilərsiniz. Onları çap edin və ya parol menecerində saxlayın.') }}</p>
                <ul class="grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4">@foreach ($recoveryCodes as $c)<li class="rounded-md bg-surface-2 px-2 py-1 text-center">{{ $c }}</li>@endforeach</ul>
            </div>
        @endif

        @if ($enabled)
            <div class="grid gap-4 p-4 md:grid-cols-2">
                <form method="POST" action="{{ route('account.2fa.codes') }}" class="flex flex-col gap-2">
                    @csrf
                    <div class="text-sm text-muted">{{ __('Qalan ehtiyat kod: :n', ['n' => $recoveryLeft]) }}</div>
                    <label class="label" for="pw-codes">{{ __('Cari parol') }}</label>
                    <div class="flex flex-wrap gap-2"><input id="pw-codes" type="password" name="current_password" required autocomplete="current-password" class="input min-w-0 flex-1">
                        <button class="btn">{{ __('Yeni ehtiyat kodlar') }}</button></div>
                </form>
                @unless ($required)
                    <form method="POST" action="{{ route('account.2fa.disable') }}" class="flex flex-col gap-2" onsubmit="return confirm(@js(__('İki mərhələli giriş söndürülsün?')))">
                        @csrf @method('DELETE')
                        <div class="text-sm text-muted">{{ __('Söndürmək üçün parolu təsdiqləyin.') }}</div>
                        <label class="label" for="pw-off">{{ __('Cari parol') }}</label>
                        <div class="flex flex-wrap gap-2"><input id="pw-off" type="password" name="current_password" required autocomplete="current-password" class="input min-w-0 flex-1">
                            <button class="btn btn-danger">{{ __('Söndür') }}</button></div>
                    </form>
                @endunless
            </div>
        @else
            <div class="grid gap-6 p-4 md:grid-cols-[220px_minmax(0,1fr)]">
                <div class="mx-auto w-[200px] rounded-lg bg-white p-2 [&_svg]:h-auto [&_svg]:w-full" role="img" aria-label="{{ __('QR kod') }}">{!! $setup['qr'] !!}</div>
                <div class="flex min-w-0 flex-col gap-3 text-sm">
                    <ol class="list-decimal space-y-1 pl-5">
                        <li>{{ __('Telefonda Google Authenticator və ya Microsoft Authenticator tətbiqini açın.') }}</li>
                        <li>{{ __('«+» → «QR kodu skan et» seçin və soldakı kodu skan edin.') }}</li>
                        <li>{{ __('Tətbiqdə görünən 6 rəqəmli kodu aşağıya yazın.') }}</li>
                    </ol>
                    <div>
                        <div class="label">{{ __('Skan etmək olmursa, açarı əl ilə yazın') }}</div>
                        <code class="block break-all rounded-md bg-surface-2 px-2 py-1 font-mono">{{ $setup['secret'] }}</code>
                        <a href="{{ $setup['uri'] }}" class="mt-1 inline-block text-primary-text hover:underline sm:hidden">{{ __('Bu telefonda authenticator tətbiqində aç') }}</a>
                    </div>
                    <form method="POST" action="{{ route('account.2fa.confirm') }}" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <div><label class="label" for="otp">{{ __('Kod') }}</label>
                            <input id="otp" name="otp" inputmode="numeric" maxlength="7" autocomplete="one-time-code" required class="input w-36 text-center font-mono tracking-[0.2em]"></div>
                        <button class="btn btn-primary">{{ __('Təsdiqlə və qoş') }}</button>
                    </form>
                </div>
            </div>
        @endif
    </section>

    <section class="card">
        <div class="card-head"><div>
            <h2 class="card-title">{{ __('Gündəlik e-mail xülasəsi') }}</h2>
            <div class="card-sub">{{ __('Hər səhər 08:00-dan sonra :email ünvanına: dünənki satış və ödənişlər, ay ərzində satış və diqqət tələb edən bildirişlər.', ['email' => $user->email]) }}</div>
        </div></div>
        @unless ($mailReady)
            <div class="border-b border-line px-4 py-3 text-sm warn">{{ __('Serverdə e-poçt (SMTP) hələ qurulmayıb — məktublar göndərilmir, yalnız jurnala yazılır.') }}</div>
        @endunless
        <div class="flex flex-wrap items-center gap-3 p-4">
            <form method="POST" action="{{ route('account.digest') }}" class="flex items-center gap-3">
                @csrf
                <input type="hidden" name="digest_enabled" value="{{ $user->digest_enabled ? 0 : 1 }}">
                <span class="text-sm">{{ $user->digest_enabled ? __('Açıqdır') : __('Bağlıdır') }}</span>
                <button @class(['btn btn-sm', 'btn-primary' => ! $user->digest_enabled])>{{ $user->digest_enabled ? __('Söndür') : __('Abunə ol') }}</button>
            </form>
            <form method="POST" action="{{ route('account.digest.test') }}" class="sm:ml-auto">
                @csrf
                <button class="btn btn-sm"><x-icon name="send" size="14"/> {{ __('İndi özümə göndər (yoxlama)') }}</button>
            </form>
        </div>
    </section>
</x-layouts.app>
