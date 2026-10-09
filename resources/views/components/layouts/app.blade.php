<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Analitika' }} · {{ config('app.name') }}</title>
    <script>
        (function () {
            var t = 'system';
            try { t = localStorage.getItem('theme') || 'system'; } catch (e) {}
            var dark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
        })();
    </script>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen font-sans antialiased" x-data="{ nav: false }">
@php
    $user = auth()->user();
    $isAdmin = $user?->isAdmin();
    $menu = [
        'Hesabatlar' => [
            ['dashboard', 'İdarə paneli', 'dashboard', true],
            ['orders.index', 'Sifarişlər və qazanc', 'orders', true],
            ['sales.index', 'Satış hesabatı', 'sales', true],
            ['margin.index', 'Marjinallıq', 'margin', true],
            ['returns.index', 'Qaytarmalar', 'returns', true],
        ],
        'Müştərilər' => [
            ['customers.index', 'Müştəri bazası', 'customers', true],
            ['debts.index', 'Müştəri borcları', 'debts', true],
            ['payments.index', 'Ödənişlər', 'payments', true],
        ],
        'Anbar' => [
            ['stock.index', 'Anbar qalığı və dəyəri', 'stock', true],
            ['movements.index', 'Anbar hərəkətləri', 'movements', true],
            ['purchases.index', 'İdxal partiyaları', 'truck', $isAdmin],
        ],
        'Maliyyə' => [
            ['expenses.index', 'Xərclər', 'expenses', $isAdmin],
            ['pnl.index', 'Mənfəət-zərər (P&L)', 'pnl', $isAdmin],
            ['cash.index', 'Kassa', 'cash', $isAdmin],
        ],
        'Məlumat' => [
            ['quality.index', 'Məlumat keyfiyyəti', 'quality', $isAdmin],
            ['import.index', 'Import', 'import', $isAdmin],
            ['aliases.index', 'Ad uyğunlaşdırma', 'link', $isAdmin],
            ['categories.index', 'Xərc kateqoriyaları', 'tag', $isAdmin],
            ['users.index', 'İstifadəçilər', 'users', $isAdmin],
        ],
    ];
@endphp

<div class="flex min-h-screen">
    {{-- Sol menyu --}}
    <div class="fixed inset-0 z-30 bg-black/40 lg:hidden" x-show="nav" x-cloak @click="nav = false"></div>
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-line bg-surface transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
           :class="nav ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
        <div class="flex h-14 items-center gap-2.5 border-b border-line px-4">
            <div class="grid size-8 place-items-center rounded-lg bg-primary text-sm font-bold text-white">V</div>
            <div class="leading-tight">
                <div class="text-sm font-semibold">Valvo Analitika</div>
                <div class="text-[11px] text-muted">Satış · Mənfəət · Anbar</div>
            </div>
            <button class="ml-auto rounded-md p-1 text-muted lg:hidden" @click="nav = false" aria-label="Menyunu bağla"><x-icon name="x"/></button>
        </div>
        <nav class="flex-1 overflow-y-auto px-2 pb-6">
            @foreach ($menu as $section => $items)
                @php $visible = array_filter($items, fn ($i) => $i[3]); @endphp
                @if ($visible)
                    <div class="nav-section">{{ $section }}</div>
                    @foreach ($visible as [$route, $label, $icon])
                        @php $active = request()->routeIs(\Illuminate\Support\Str::beforeLast($route, '.').'*') || request()->routeIs($route); @endphp
                        <a href="{{ route($route) }}" @class(['nav-link', 'active' => $active]) @if($active) aria-current="page" @endif>
                            <x-icon :name="$icon"/> <span>{{ $label }}</span>
                        </a>
                    @endforeach
                @endif
            @endforeach
        </nav>
        @php $rebuild = \App\Services\AnalyticsRebuilder::status(); @endphp
        <div class="border-t border-line px-4 py-3 text-[11px] text-muted">
            @if (($rebuild['status'] ?? null) === 'running' || ($rebuild['status'] ?? null) === 'queued')
                <span class="warn">● Hesablanır: {{ $rebuild['pct'] ?? 0 }}%</span>
            @elseif (isset($rebuild['finished_at']) && ($rebuild['status'] ?? null) === 'done')
                Son hesablama: {{ \Illuminate\Support\Carbon::parse($rebuild['finished_at'])->format('d.m.Y H:i') }}
            @endif
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur lg:px-6">
            <button class="rounded-md p-1.5 text-muted hover:bg-surface-2 lg:hidden" @click="nav = true" aria-label="Menyunu aç"><x-icon name="menu"/></button>
            <div class="min-w-0">
                <h1 class="truncate text-[15px] font-semibold">{{ $title ?? '' }}</h1>
            </div>
            <div class="ml-auto flex items-center gap-1.5">
                <button type="button" class="btn btn-sm" onclick="toggleTheme()" aria-label="Qaranlıq / işıqlı rejim" title="Qaranlıq / işıqlı rejim">
                    <x-icon name="moon" size="16" class="dark:hidden"/><x-icon name="sun" size="16" class="hidden dark:block"/>
                </button>
                @if ($user)
                    <div class="hidden text-right text-xs leading-tight sm:block">
                        <div class="font-medium">{{ $user->name }}</div>
                        <div class="text-muted">{{ $user->isAdmin() ? 'Admin' : 'Menecer' }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn btn-sm" title="Çıxış" aria-label="Çıxış"><x-icon name="logout" size="16"/></button>
                    </form>
                @endif
            </div>
        </header>

        <main class="mx-auto w-full max-w-[1500px] flex-1 space-y-4 px-4 py-4 lg:px-6 lg:py-6">
            @if (session('status'))
                <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--positive)">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--negative)">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--negative)">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>
@livewireScripts
</body>
</html>
