@php use App\Support\Fmt; @endphp
<div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="text-base font-semibold" data-modal-title>{{ $title }}</h2>
        <div class="text-xs text-muted">{{ __('MZ (vergi bəyannaməsi)') }} · {{ $year }}</div>
    </div>
    @isset($amount)
        <div class="shrink-0 text-right">
            <div class="text-xs text-muted">{{ __('MZ-də') }}</div>
            <div class="text-lg font-semibold tabular-nums">{{ Fmt::money($amount) }}</div>
        </div>
    @endisset
</div>

<div class="space-y-5 px-5 py-4">
    @isset($groupLines)
        {{-- qrup sətri: maddələrin tərkibi --}}
        <div class="tbl-wrap rounded-lg border border-line">
            <table class="tbl">
                <thead><tr><th>{{ __('Kod') }}</th><th>{{ __('Maddə') }}</th><th class="num">{{ __('Məbləğ') }}</th><th class="num">{{ __('Pay') }}</th></tr></thead>
                <tbody>
                @foreach ($groupLines as $l)
                    <tr>
                        <td class="font-mono text-xs">{{ $l->code }}</td>
                        <td class="wrap"><a href="{{ route('accounting.pl.detail', ['year' => $year, 'code' => $l->code, 'name' => $l->name]) }}" data-modal class="cell-link">{{ $l->name }}</a></td>
                        <td class="num {{ $l->amount < 0 ? 'neg' : '' }}">{{ Fmt::money($l->amount) }}</td>
                        <td class="num text-muted">{{ $l->share === null ? '—' : Fmt::pct($l->share) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr><td colspan="2">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::money($groupLines->sum('amount')) }}</td><td></td></tr></tfoot>
            </table>
        </div>
        <div class="text-xs text-muted">{{ __('Maddəyə klik — bank ödənişləri və sistemdəki qarşılığı.') }}</div>
    @else
        <div class="rounded-lg border border-line bg-surface-2 px-4 py-3 text-sm">
            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Mənbə') }}</div>
            {{ __('Mühasibat faylının MZ vərəqi — illik məbləğ (vergi bəyannaməsi kodu ilə). Faylda bu məbləğin əməliyyat siyahısı yoxdur; aşağıda onun qarşılığı göstərilir.') }}
            @if ($note)<div class="mt-1 text-muted">{{ $note }}</div>@endif
        </div>

        @php $bankTotal = (float) $bank->sum('amount'); $systemTotal = (float) array_sum(array_column($system, 'amount')); @endphp
        <div class="overflow-hidden rounded-lg border border-line">
            <table class="tbl">
                <tbody>
                <tr><td>{{ __('MZ-də (hesablanmış)') }}</td><td class="num font-semibold">{{ Fmt::money($amount) }}</td></tr>
                @if ($bankTitle)<tr><td>{{ $bankTitle }}</td><td class="num">{{ Fmt::money($bankTotal) }}</td></tr>@endif
                @if ($system)<tr><td>{{ $systemTitle }}</td><td class="num">{{ Fmt::money($systemTotal) }}</td></tr>@endif
                @if ($bankTitle && $kind !== 'depreciation')
                    <tr class="text-muted"><td>{{ __('Fərq: MZ − bank') }}</td><td class="num">{{ Fmt::money($amount - $bankTotal) }}</td></tr>
                @endif
                </tbody>
            </table>
        </div>

        @if ($system)
            <section>
                <h3 class="mb-2 text-sm font-semibold">{{ $systemTitle }}</h3>
                <div class="overflow-hidden rounded-lg border border-line">
                    <table class="tbl">
                        <tbody>
                        @foreach ($system as $part)
                            <tr>
                                <td class="wrap">@if ($part['url'])<a href="{{ $part['url'] }}" data-modal class="cell-link">{{ $part['label'] }}</a>@else{{ $part['label'] }}@endif</td>
                                <td class="num {{ $part['amount'] < 0 ? 'neg' : '' }}">{{ Fmt::money($part['amount']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($bankTitle)
            <section>
                <h3 class="mb-2 text-sm font-semibold">{{ $bankTitle }} <span class="font-normal text-muted">· {{ __(':n sətir', ['n' => $bank->count()]) }}</span></h3>
                <div class="tbl-wrap max-h-[45vh] rounded-lg border border-line">
                    <table class="tbl">
                        <thead><tr><th>{{ __('Kontragent') }}</th><th class="num">{{ __('Ay sayı') }}</th><th class="num">{{ __('Məbləğ') }}</th></tr></thead>
                        <tbody>
                        @forelse ($bank->groupBy('counterparty')->map(fn ($g) => (object) ['name' => $g->first()->counterparty, 'months' => $g->pluck('period')->unique()->count(), 'total' => (float) $g->sum('amount'), 'periods' => $g->pluck('period')->unique()->sort()->values()])->sortByDesc('total') as $c)
                            <tr>
                                <td class="wrap">{{ $c->name }}<div class="text-xs text-muted">{{ $c->periods->map(fn ($p) => Fmt::period($p))->implode(', ') }}</div></td>
                                <td class="num text-muted">{{ $c->months }}</td>
                                <td class="num">{{ Fmt::money($c->total) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="py-6 text-center text-muted">{{ __('Bank hərəkəti yoxdur.') }}</td></tr>
                        @endforelse
                        </tbody>
                        @if ($bank->isNotEmpty())<tfoot><tr><td colspan="2">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::money($bankTotal) }}</td></tr></tfoot>@endif
                    </table>
                </div>
            </section>
        @endif
    @endisset
</div>
