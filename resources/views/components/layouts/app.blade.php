<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('Analitika') }} · {{ config('app.name') }}</title>
    <script>
        (function () {
            var t = 'system';
            try { t = localStorage.getItem('theme') || 'system'; } catch (e) {}
            var dark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            if (dark) document.documentElement.classList.add('dark');
        })();
    </script>
    <script>window.i18n = @json(\App\Support\Locale::jsStrings());</script>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen font-sans antialiased" x-data="{ nav: false }">
@php
    $user = auth()->user();
    $isAdmin = $user?->isAdmin();
    $menu = [
        __('Hesabatlar') => [
            ['dashboard', __('İdarə paneli'), 'dashboard', true],
            ['sales.index', __('Satış hesabatı'), 'sales', true],
            ['margin.index', __('Marjinallıq'), 'margin', true],
        ],
        __('Satış və Kampaniyalar') => [
            ['orders.index', __('Sifarişlər və qazanc'), 'orders', true],
            ['deliveries.index', __('Təhvil-təslim sənədləri'), 'truck', true],
            ['invoices.index', __('Satış fakturaları'), 'invoice', true],
            ['returns.index', __('Qaytarmalar'), 'returns', true],
            ['campaigns.index', __('Kampaniyalar'), 'tag', true],
        ],
        __('Məhsul idarəetməsi') => [
            ['products.index', __('Məhsullar'), 'stock', true],
            ['prices.index', __('Qiymət kartları'), 'tag', $isAdmin],
            ['prices.list', __('Qiymət siyahısı'), 'invoice', true],
            ['product-settings.index', __('Məhsul parametrləri'), 'filter', $isAdmin],
        ],
        __('Müştərilər') => [
            ['customers.index', __('Müştəri bazası'), 'customers', true],
            ['debts.index', __('Müştəri borcları'), 'debts', true],
            ['payments.index', __('Ödənişlər'), 'payments', true],
        ],
        __('Anbar') => [
            ['stock.index', __('Anbar qalığı və dəyəri'), 'stock', true],
            ['movements.index', __('Anbar hərəkətləri'), 'movements', true],
            ['transfers.index', __('Anbar transferləri'), 'truck', true],
            ['batches.index', __('Son istifadə tarixləri'), 'warning', true],
            ['write-offs.index', __('Silinmə aktları'), 'trash', true],
            ['warehouses.index', __('Anbarlar'), 'stock', true],
            ['purchases.index', __('İdxal partiyaları'), 'truck', $isAdmin],
        ],
        __('Maliyyə') => [
            ['expenses.index', __('Xərclər'), 'expenses', $isAdmin],
            ['pnl.index', __('Mənfəət-zərər (P&L)'), 'pnl', $isAdmin],
            ['flowsheet.index', __('Cash Flow (idarəetmə)'), 'cash', $isAdmin],
            ['cash.index', __('Kassa'), 'cash', $isAdmin],
        ],
        __('Mühasibat') => [
            ['accounting.chart', __('Hesablar planı'), 'tag', $isAdmin],
            ['accounting.balance', __('Balans'), 'pnl', $isAdmin],
            ['accounting.pl', __('Mənfəət-zərər (vergi)'), 'expenses', $isAdmin],
            ['accounting.cashflow', __('Cash Flow (bank)'), 'cash', $isAdmin],
            ['bank.index', __('Bank hesabları'), 'payments', $isAdmin],
            ['accounting.overview', __('İllər üzrə müqayisə'), 'up', $isAdmin],
            ['accounting.reconcile', __('Tutuşdurma'), 'quality', $isAdmin],
        ],
        __('Məlumat') => [
            ['quality.index', __('Məlumat keyfiyyəti'), 'quality', $isAdmin],
            ['import.index', __('Import'), 'import', $isAdmin],
            ['aliases.index', __('Ad uyğunlaşdırma'), 'link', $isAdmin],
            ['categories.index', __('Xərc kateqoriyaları'), 'tag', $isAdmin],
            ['users.index', __('İstifadəçilər'), 'users', $isAdmin],
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
                <div class="text-sm font-semibold">{{ __('Valvo Analitika') }}</div>
                <div class="text-[11px] text-muted">{{ __('Satış · Mənfəət · Anbar') }}</div>
            </div>
            <button class="ml-auto rounded-md p-1 text-muted lg:hidden" @click="nav = false" aria-label="{{ __('Menyunu bağla') }}"><x-icon name="x"/></button>
        </div>
        <nav class="flex-1 overflow-y-auto px-2 pb-6">
            @foreach ($menu as $section => $items)
                @php $visible = array_filter($items, fn ($i) => $i[3]); @endphp
                @if ($visible)
                    <div class="nav-section">{{ $section }}</div>
                    @foreach ($visible as [$route, $label, $icon])
                        @php $active = request()->routeIs((str_ends_with($route, '.index') ? \Illuminate\Support\Str::beforeLast($route, '.') : $route).'*'); @endphp
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
                <span class="warn">● {{ __('Hesablanır') }}: {{ $rebuild['pct'] ?? 0 }}%</span>
            @elseif (isset($rebuild['finished_at']) && ($rebuild['status'] ?? null) === 'done')
                {{ __('Son hesablama') }}: {{ \Illuminate\Support\Carbon::parse($rebuild['finished_at'])->format('d.m.Y H:i') }}
            @endif
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-line bg-surface/90 px-4 backdrop-blur lg:px-6">
            <button class="rounded-md p-1.5 text-muted hover:bg-surface-2 lg:hidden" @click="nav = true" data-nav-open aria-label="{{ __('Menyunu aç') }}"><x-icon name="menu"/></button>
            <div class="min-w-0">
                <h1 class="truncate text-[15px] font-semibold">{{ $title ?? '' }}</h1>
            </div>
            <div class="ml-auto flex items-center gap-1.5">
                <x-locale-switch/>
                <button type="button" class="btn btn-sm" onclick="toggleTheme()" aria-label="{{ __('Qaranlıq / işıqlı rejim') }}" title="{{ __('Qaranlıq / işıqlı rejim') }}">
                    <x-icon name="moon" size="16" class="dark:hidden"/><x-icon name="sun" size="16" class="hidden dark:block"/>
                </button>
                @if ($user)
                    <div class="hidden text-right text-xs leading-tight sm:block">
                        <div class="font-medium">{{ $user->name }}</div>
                        <div class="text-muted">{{ $user->isAdmin() ? __('Admin') : __('Menecer') }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn btn-sm" title="{{ __('Çıxış') }}" aria-label="{{ __('Çıxış') }}"><x-icon name="logout" size="16"/></button>
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
{{-- Detal popup: data-modal atributlu keçidlər burada açılır (resources/js/app.js) --}}
<dialog id="detail-modal" aria-labelledby="detail-modal-title"
        class="m-auto w-[min(1100px,96vw)] max-w-none overflow-hidden rounded-xl border border-line bg-surface p-0 text-ink shadow-2xl backdrop:bg-black/50">
    <div class="flex max-h-[88vh] flex-col">
        <div class="flex items-center justify-between gap-2 border-b border-line bg-surface-2 px-5 py-2">
            <span id="detail-modal-title" class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Detal') }}</span>
            <div class="flex items-center gap-1.5">
                <a data-modal-open-page href="#" class="btn btn-sm" target="_blank" rel="noopener">{{ __('Ayrıca səhifədə aç') }}</a>
                <button type="button" class="btn btn-sm" data-modal-close aria-label="{{ __('Bağla') }}" autofocus><x-icon name="x" size="14"/></button>
            </div>
        </div>
        <div data-modal-body class="min-h-24 overflow-y-auto"></div>
    </div>
</dialog>
{{-- Əməliyyat popupu: «… nömrəli … sənədi yaradıldı» --}}
@if ($popup = session('popup'))
    <dialog id="action-popup" aria-labelledby="action-popup-title" x-data x-init="$el.showModal()"
            class="m-auto w-[min(460px,92vw)] rounded-xl border border-line bg-surface p-0 text-ink shadow-2xl backdrop:bg-black/50">
        <div class="flex flex-col gap-3 p-5">
            <div class="flex items-center gap-3">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-[var(--positive-soft,var(--primary-soft))] pos" aria-hidden="true">✓</span>
                <h2 id="action-popup-title" class="text-base font-semibold">{{ $popup['title'] }}</h2>
            </div>
            @if (! empty($popup['message']))<p class="text-sm">{{ $popup['message'] }}</p>@endif
            @if (! empty($popup['docs']))
                <ul class="flex flex-col gap-1.5 text-sm">
                    @foreach ($popup['docs'] as $doc)
                        <li><a href="{{ $doc['url'] }}" class="text-primary-text hover:underline">{{ __('№ :no nömrəli :type yaradıldı.', ['no' => $doc['no'], 'type' => mb_strtolower($doc['type'])]) }}</a></li>
                    @endforeach
                </ul>
            @endif
            <div class="flex justify-end"><button type="button" class="btn btn-primary" autofocus @click="$el.closest('dialog').close()">{{ __('Oldu') }}</button></div>
        </div>
    </dialog>
@endif
@livewireScripts
</body>
</html>
