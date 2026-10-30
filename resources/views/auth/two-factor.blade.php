<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Təsdiq kodu') }} · {{ config('app.name') }}</title>
    <script>
        (function () {
            var t = 'system';
            try { t = localStorage.getItem('theme') || 'system'; } catch (e) {}
            if (t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark');
        })();
    </script>
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center px-4 font-sans antialiased">
<main class="w-full max-w-sm">
    <div class="mb-6 flex items-center gap-3">
        <div class="grid size-10 place-items-center rounded-xl bg-primary text-lg font-bold text-white">V</div>
        <div>
            <div class="text-lg font-semibold leading-tight">{{ __('Valvo Analitika') }}</div>
            <div class="text-sm text-muted">{{ __('İki mərhələli giriş') }}</div>
        </div>
    </div>
    <form method="POST" action="{{ route('two-factor.verify') }}" class="card space-y-4 p-6">
        @csrf
        <h1 class="text-base font-semibold">{{ __('Təsdiq kodu') }}</h1>
        @if ($errors->any())
            <div class="rounded-lg px-3 py-2 text-sm" style="background: var(--negative-soft); color: var(--negative)">{{ $errors->first() }}</div>
        @endif
        <div>
            <label class="label" for="otp">{{ __('Authenticator tətbiqindəki 6 rəqəmli kod') }}</label>
            <input id="otp" name="otp" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" autofocus class="input text-center font-mono text-lg tracking-[0.3em]">
        </div>
        <details class="text-sm">
            <summary class="cursor-pointer text-muted">{{ __('Telefon yanımda deyil — ehtiyat kodla daxil ol') }}</summary>
            <div class="mt-2"><label class="label" for="recovery_code">{{ __('Ehtiyat kod') }}</label>
                <input id="recovery_code" name="recovery_code" maxlength="20" autocomplete="off" class="input font-mono" placeholder="abcde-12345"></div>
        </details>
        <button class="btn btn-primary w-full">{{ __('Təsdiqlə') }}</button>
        <a href="{{ route('login') }}" class="block text-center text-sm text-muted hover:underline">{{ __('Başqa hesabla daxil ol') }}</a>
    </form>
    <x-locale-switch class="mt-4 justify-center"/>
</main>
</body>
</html>
