<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Giriş') }} · {{ config('app.name') }}</title>
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
            <div class="text-sm text-muted">{{ __('Satış, mənfəət, anbar və müştəri analitikası') }}</div>
        </div>
    </div>
    <form method="POST" action="{{ url('/login') }}" class="card space-y-4 p-6">
        @csrf
        <h1 class="text-base font-semibold">{{ __('Sistemə giriş') }}</h1>
        @if ($errors->any())
            <div class="rounded-lg px-3 py-2 text-sm" style="background: var(--negative-soft); color: var(--negative)">{{ $errors->first() }}</div>
        @endif
        <div>
            <label class="label" for="email">{{ __('E-poçt') }}</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input">
        </div>
        <div>
            <label class="label" for="password">{{ __('Parol') }}</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
        </div>
        <label class="flex items-center gap-2 text-sm text-muted">
            <input type="checkbox" name="remember" value="1" class="size-4"> {{ __('Məni xatırla') }}
        </label>
        <button class="btn btn-primary w-full">{{ __('Daxil ol') }}</button>
    </form>
    <x-locale-switch class="mt-4 justify-center"/>
</main>
</body>
</html>
