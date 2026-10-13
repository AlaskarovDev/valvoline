@php use App\Support\Fmt; $totals = $rows->whereNull('document'); @endphp
<div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="text-base font-semibold" data-modal-title>{{ $title }}</h2>
        <div class="text-xs text-muted">{{ $year }} · @if ($chart){{ __('yeni hesab') }} {{ $chart->chart_code }} — {{ $chart->name }}@endif</div>
    </div>
    <div class="shrink-0 text-right text-sm tabular-nums">
        <div>{{ __('Debet') }}: <b>{{ Fmt::money($totals->sum('debit')) }}</b></div>
        <div>{{ __('Kredit') }}: <b>{{ Fmt::money($totals->sum('credit')) }}</b></div>
    </div>
</div>
<div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>{{ __('Kontragent / sənəd') }}</th><th class="num">{{ __('Debet') }}</th><th class="num">{{ __('Kredit') }}</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr @class(['font-semibold' => $r->document === null])>
                <td class="wrap {{ $r->document ? 'pl-8 text-xs text-muted' : '' }}">{{ $r->document ?? $r->counterparty }}</td>
                <td class="num">{{ (float) $r->debit != 0 ? Fmt::money($r->debit) : '' }}</td>
                <td class="num">{{ (float) $r->credit != 0 ? Fmt::money($r->credit) : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="py-8 text-center text-muted">{{ __('Məlumat yoxdur') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
