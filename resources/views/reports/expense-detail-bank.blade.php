@php use App\Support\Fmt; @endphp
<div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="text-base font-semibold" data-modal-title>{{ $name }} <span class="badge badge-primary align-middle">{{ __('Rəsmi') }}</span></h2>
        <div class="text-xs text-muted">{{ $periodLabel }} · {{ __('mühasibat faylındakı bank ödənişləri (Cash Flow) — kassada yoxdur') }}</div>
    </div>
    <div class="shrink-0 text-right">
        <div class="text-lg font-bold tabular-nums">{{ Fmt::money($total) }}</div>
        <div class="text-xs text-muted">{{ __(':n əməliyyat', ['n' => $rows->count()]) }}</div>
    </div>
</div>

@if ($byCounterparty->count() > 1)
    <div class="flex flex-wrap gap-1.5 border-b border-line px-5 py-3">
        @foreach ($byCounterparty as $counterparty => $sum)
            <span class="badge">{{ $counterparty }} · <b class="tabular-nums">{{ Fmt::money($sum) }}</b></span>
        @endforeach
    </div>
@endif

<div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>{{ __('Ay') }}</th><th>{{ __('Kontragent') }}</th><th class="num">{{ __('Məbləğ') }}</th><th>{{ __('Təsnifat') }}</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ Fmt::period($r->period) }}</td>
                <td class="wrap">{{ $r->counterparty }}</td>
                <td class="num neg">{{ Fmt::money($r->amount) }}</td>
                <td class="wrap text-xs text-muted">{{ $r->note }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-8 text-center text-muted">{{ __('Əməliyyat yoxdur') }}</td></tr>
        @endforelse
        </tbody>
        <tfoot><tr><td colspan="2">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::money($total) }}</td><td></td></tr></tfoot>
    </table>
</div>
<div class="border-t border-line bg-surface-2 px-5 py-3 text-xs text-muted">
    {{ __('Mühasibat faylında bank ödənişləri ay üzrə cəmlənib (gün yoxdur). Növü səhvdirsə Cash Flow səhifəsində dəyişin.') }}
    <a href="{{ route('accounting.cashflow', ['year' => $year, 'type' => 'expense']) }}" class="font-medium text-primary hover:underline">Cash Flow →</a>
</div>
