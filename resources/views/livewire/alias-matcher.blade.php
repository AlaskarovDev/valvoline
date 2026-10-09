@php use App\Support\Fmt; @endphp
<div class="space-y-4">
    @if ($message)
        <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--positive)" role="status">{{ $message }}</div>
    @endif

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="card overflow-hidden">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Uyğunlaşdırılmamış adlar</h2>
                    <div class="card-sub">Satış, qaytarma və ödənişlərdə müştəri kodu tapılmayan adlar</div>
                </div>
                <span class="badge {{ $unmatched->isEmpty() ? 'badge-pos' : 'badge-warn' }}">{{ $unmatched->count() }}</span>
            </div>
            <ul class="max-h-[60vh] divide-y divide-line overflow-y-auto">
                @forelse ($unmatched as $row)
                    <li>
                        <button type="button" wire:click="select(@js($row->name))"
                                @class(['flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm hover:bg-surface-2', 'bg-primary-soft' => $selected === $row->name])>
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ $row->name }}</span>
                                @if ($row->code)<span class="text-xs text-muted">kod: {{ $row->code }}</span>@endif
                            </span>
                            <span class="shrink-0 text-right text-xs text-muted">
                                <span class="num block">{{ $row->cnt }} sətir</span>
                                <span class="num">{{ Fmt::money($row->amount) }}</span>
                            </span>
                        </button>
                    </li>
                @empty
                    <li class="px-4 py-10 text-center text-sm text-muted">Bütün adlar uyğunlaşdırılıb ✓</li>
                @endforelse
            </ul>
        </section>

        <section class="card overflow-hidden">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ $selected ? '«'.$selected.'» üçün müştəri seçin' : 'Ad seçin' }}</h2>
                    <div class="card-sub">Oxşar adlar avtomatik təklif olunur (ə→e, ı→i, ö→o, ü→u, ş→s, ç→c, ğ→g)</div>
                </div>
            </div>
            @if ($selected)
                <div class="border-b border-line p-3">
                    <label class="sr-only" for="alias-search">Müştəri axtar</label>
                    <input id="alias-search" type="search" wire:model.live.debounce.300ms="search" class="input" placeholder="Ad və ya kodla axtar…">
                </div>
                <ul class="divide-y divide-line">
                    @forelse ($results as $c)
                        <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ $c->name }}</span>
                                <span class="text-xs text-muted">kod {{ $c->code }}@isset($c->score) · oxşarlıq {{ Fmt::pct($c->score, 0) }}@endisset</span>
                            </span>
                            <button type="button" class="btn btn-sm btn-primary" wire:click="match({{ $c->id }})" wire:loading.attr="disabled">Uyğunlaşdır</button>
                        </li>
                    @empty
                        <li class="px-4 py-8 text-center text-sm text-muted">Tapılmadı</li>
                    @endforelse
                </ul>
            @else
                <div class="px-4 py-10 text-center text-sm text-muted">Soldakı siyahıdan uyğunlaşdırılacaq adı seçin.</div>
            @endif
        </section>
    </div>

    <section class="card overflow-hidden">
        <div class="card-head"><h2 class="card-title">Əl ilə uyğunlaşdırılmış adlar</h2><span class="card-sub">Təkrar importda da qüvvədə qalır</span></div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Orijinal ad</th><th>Müştəri</th><th>Tarix</th><th></th></tr></thead>
                <tbody>
                @forelse ($manual as $alias)
                    <tr>
                        <td>{{ $alias->original_name }}</td>
                        <td>@if ($alias->customer)<a class="cell-link" href="{{ route('customers.show', $alias->customer) }}">{{ $alias->customer->name }} · {{ $alias->customer->code }}</a>@else — @endif</td>
                        <td>{{ Fmt::date($alias->created_at) }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('aliases.destroy', $alias) }}">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-danger">Sil</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-muted">Yoxdur</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
