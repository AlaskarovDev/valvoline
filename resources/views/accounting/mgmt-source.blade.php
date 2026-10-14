@php use App\Support\Fmt; @endphp
<div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="text-base font-semibold" data-modal-title>{{ $title }}</h2>
        <div class="text-xs text-muted">{{ $account->name }} ·
            {{ $isTurnover ? __('dövriyyə :from – :to', ['from' => Fmt::date($date->copy()->startOfYear()), 'to' => Fmt::date($date)]) : __('qalıq :date tarixinə', ['date' => Fmt::date($date)]) }}</div>
    </div>
    <div class="shrink-0 text-right">
        <div class="text-xs text-muted">{{ __('İdarəetmə') }}</div>
        <div class="text-lg font-semibold tabular-nums {{ $total < 0 ? 'neg' : '' }}">{{ Fmt::money($total) }}</div>
    </div>
</div>

<div class="space-y-3 px-5 py-4">
    <div class="text-sm text-muted">{{ __('Sistemdəki importlardan (01–08 fayllar) yığılır. Sətrə klik — həmin məbləğin əməliyyatları.') }}</div>
    <div class="overflow-hidden rounded-lg border border-line">
        <table class="tbl">
            <tbody>
            @forelse ($parts as $part)
                <tr>
                    <td class="wrap">
                        @if ($part['url'])
                            <a href="{{ $part['url'] }}" @if (str_contains($part['url'], '/detal')) data-modal @endif class="cell-link">{{ $part['label'] }}</a>
                        @else
                            {{ $part['label'] }}
                        @endif
                    </td>
                    <td class="num {{ $part['amount'] < 0 ? 'neg' : '' }}">{{ Fmt::money($part['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="py-6 text-center text-muted">{{ __('Bu hesaba sistem importlarından hərəkət düşmür.') }}</td></tr>
            @endforelse
            </tbody>
            @if (count($parts) > 1)
                <tfoot><tr><td>{{ __('Cəmi') }}</td><td class="num">{{ Fmt::money($total) }}</td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
