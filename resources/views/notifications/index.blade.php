<x-layouts.app :title="$title">
    <div class="card p-4 text-sm text-muted">{{ __('Diqqət tələb edən vəziyyətlər. Hər bildiriş yalnız sizin girişiniz olan bölmələr üzrə göstərilir və sənəd yazıldıqca yenilənir.') }}</div>
    @if (! $alerts)
        <div class="card px-4 py-10 text-center text-sm text-muted">{{ __('Diqqət tələb edən heç nə yoxdur.') }}</div>
    @else
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($alerts as $a)
                <a href="{{ $a['url'] }}" class="card flex items-start gap-3 border-l-4 p-4 hover:bg-surface-2" style="border-left-color: {{ $a['level'] === 'danger' ? 'var(--negative)' : 'var(--warning, #d97706)' }}">
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold">{{ $a['title'] }}</div>
                        <div class="text-sm text-muted">{{ $a['detail'] }}</div>
                    </div>
                    <span @class(['badge', 'badge-neg' => $a['level'] === 'danger', 'badge-warn' => $a['level'] !== 'danger'])>{{ $a['level'] === 'danger' ? __('Təcili') : __('Diqqət') }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-layouts.app>
