{{-- Bildiriş zəngi: say və qısa siyahı səhifə açıldıqdan sonra yüklənir (səhifə gözləmir) --}}
<div class="relative" x-data="{ open: false, count: 0, danger: 0, alerts: [], loaded: false,
        load() { fetch(@js(route('notifications.summary')), { headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : null)
            .then(d => { if (d) { this.count = d.count; this.danger = d.danger; this.alerts = d.alerts; } this.loaded = true; }).catch(() => { this.loaded = true; }); } }"
     x-init="load()" @keydown.escape.window="open = false" @click.outside="open = false">
    <button type="button" class="btn btn-sm relative" @click="open = ! open" :aria-expanded="open" aria-haspopup="true"
            :aria-label="count ? @js(__('Bildirişlər')) + ': ' + count : @js(__('Bildirişlər'))" title="{{ __('Bildirişlər') }}">
        <x-icon name="bell" size="16"/>
        <span x-show="count" x-cloak x-text="count" class="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full px-1 text-[10px] font-semibold leading-none text-white"
              :style="danger ? 'background: var(--negative)' : 'background: var(--warning, #d97706)'"></span>
    </button>
    <div x-show="open" x-cloak x-transition.opacity
         class="fixed inset-x-3 top-14 z-30 overflow-hidden sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-2 sm:w-[360px] rounded-xl border border-line bg-surface shadow-xl">
        <div class="flex items-center justify-between border-b border-line px-4 py-2.5">
            <span class="text-sm font-semibold">{{ __('Bildirişlər') }}</span>
            <a href="{{ route('notifications.index') }}" class="text-xs text-primary-text hover:underline">{{ __('Hamısı') }}</a>
        </div>
        <ul class="max-h-[60vh] divide-y divide-line overflow-y-auto">
            <template x-for="a in alerts" :key="a.key">
                <li><a :href="a.url" class="flex gap-3 px-4 py-2.5 hover:bg-surface-2">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full" :style="a.level === 'danger' ? 'background: var(--negative)' : 'background: var(--warning, #d97706)'" aria-hidden="true"></span>
                    <span class="min-w-0"><span class="block text-sm font-medium" x-text="a.title"></span><span class="block text-xs text-muted" x-text="a.detail"></span></span>
                </a></li>
            </template>
            <li x-show="loaded && ! alerts.length" class="px-4 py-6 text-center text-sm text-muted">{{ __('Diqqət tələb edən heç nə yoxdur.') }}</li>
            <li x-show="! loaded" class="px-4 py-6 text-center text-sm text-muted">{{ __('Yüklənir…') }}</li>
        </ul>
    </div>
</div>
