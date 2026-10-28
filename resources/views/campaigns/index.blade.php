@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Hazırda aktiv')" :value="Fmt::number($stats['running'])"/>
        <x-kpi :label="__('Təsdiq gözləyir')" :value="Fmt::number($stats['pending'])" :tone="$stats['pending'] > 0 ? 'neg' : null"/>
        <x-kpi :label="__('Gələcək')" :value="Fmt::number($stats['future'])"/>
        <x-kpi :label="__('Verilmiş endirim')" :value="Fmt::money($stats['discount'])"/>
    </div>

    <section class="card overflow-hidden">
        <form method="GET" class="flex flex-wrap items-end gap-2 border-b border-line px-4 py-3">
            <div class="min-w-0 flex-1 sm:flex-none"><label class="label" for="c-q">{{ __('Axtarış') }}</label><input id="c-q" name="q" value="{{ request('q') }}" placeholder="{{ __('Kod və ya ad…') }}" class="input h-[34px] text-xs sm:w-56"></div>
            <div><label class="label" for="c-from">{{ __('Başlanğıc') }}</label><input id="c-from" type="date" name="from" value="{{ request('from') }}" class="input h-[34px] text-xs"></div>
            <div><label class="label" for="c-to">{{ __('Son') }}</label><input id="c-to" type="date" name="to" value="{{ request('to') }}" class="input h-[34px] text-xs"></div>
            <div>
                <label class="label" for="c-status">{{ __('Status') }}</label>
                <select id="c-status" name="status" class="select h-[34px] text-xs">
                    <option value="">{{ __('Hamısı') }}</option>
                    @foreach ($statuses as $key => $label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach
                    <option value="rejected,cancelled" @selected(request('status') === 'rejected,cancelled')>{{ __('Rədd / ləğv') }}</option>
                </select>
            </div>
            <button class="btn btn-sm h-[34px]"><x-icon name="filter" size="14"/> {{ __('Tətbiq et') }}</button>
            <a href="{{ route('campaigns.create') }}" class="btn btn-sm btn-primary h-[34px] sm:ml-auto"><x-icon name="plus" size="14"/> {{ __('Yeni kampaniya') }}</a>
        </form>

        <div class="tbl-wrap">
            <table class="tbl w-full text-sm">
                <thead>
                    <tr class="border-b border-line text-left text-xs uppercase text-muted">
                        <th class="px-4 py-2">{{ __('Kod') }}</th>
                        <th class="px-4 py-2">{{ __('Ad') }}</th>
                        <th class="px-4 py-2">{{ __('Növ') }}</th>
                        <th class="px-4 py-2">{{ __('Tarix') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Prioritet') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Sifariş') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Endirim') }}</th>
                        <th class="px-4 py-2">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $c)
                        @php
                            $u = $usage[$c->id] ?? null;
                            $state = $c->status !== 'approved' ? null : (! $c->is_active ? __('dayandırılıb') : ($c->end_date->lt($today) ? __('bitib') : ($c->begin_date->gt($today) ? __('gələcək') : __('aktiv'))));
                        @endphp
                        <tr class="border-b border-line">
                            <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('campaigns.show', $c) }}" class="text-primary-text hover:underline">{{ $c->code }}</a></td>
                            <td class="wrap px-4 py-2"><a href="{{ route('campaigns.show', $c) }}" class="text-primary-text hover:underline">{{ $c->name }}</a></td>
                            <td class="px-4 py-2 text-xs text-muted">{{ $kinds[$c->kind] ?? $c->kind }}</td>
                            <td class="whitespace-nowrap px-4 py-2 text-xs">{{ Fmt::date($c->begin_date) }} – {{ Fmt::date($c->end_date) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $c->priority }}@if ($c->priority_group)<span class="text-xs text-muted"> · {{ $c->priority_group }}</span>@endif</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $u ? Fmt::number($u['orders']) : '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $u && $u['discount'] > 0 ? Fmt::money($u['discount']) : ($u && $u['gift_qty'] > 0 ? Fmt::qty($u['gift_qty']).' '.__('əd. hədiyyə') : '—') }}</td>
                            <td class="px-4 py-2">
                                <span @class(['badge', 'badge-primary' => $c->status === 'approved' && $state === __('aktiv'), 'badge-warn' => $c->status === 'pending'])>{{ $statuses[$c->status] ?? $c->status }}</span>
                                @if ($state)<span class="text-xs text-muted">{{ $state }}</span>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-8 text-center text-sm text-muted">{{ __('Kampaniya yoxdur.') }} <a href="{{ route('campaigns.create') }}" class="text-primary-text hover:underline">{{ __('Yeni kampaniya') }}</a></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($campaigns->hasPages())<div class="border-t border-line px-4 py-3">{{ $campaigns->links() }}</div>@endif
    </section>
</x-layouts.app>
