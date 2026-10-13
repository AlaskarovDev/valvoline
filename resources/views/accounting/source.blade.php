@php use App\Support\Fmt; $total = (float) $current->sum('amount'); $sideLabels = ['asset' => __('Aktiv'), 'liability' => __('Passiv')]; @endphp
<div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="text-base font-semibold" data-modal-title>{{ $title }}</h2>
        <div class="text-xs text-muted">{{ $account->name }} · {{ $yearLabels[$year] ?? $year }}</div>
    </div>
    <div class="shrink-0 text-right">
        <div class="text-xs text-muted">{{ __('Qalıq') }}</div>
        <div class="text-lg font-semibold tabular-nums {{ $total < 0 ? 'neg' : '' }}">{{ Fmt::money($total) }}</div>
    </div>
</div>

<div class="space-y-5 px-5 py-4">
    <div class="text-sm text-muted">
        {{ __('Bu hesabın qalığı mühasibat faylının BALANS vərəqindəki köhnə (1C) hesablarından yığılır:') }}
        @foreach ($legacy as $l)
            <span class="badge font-mono">{{ $l->code }} → {{ $account->code }}</span>
        @endforeach
    </div>

    <section>
        <h3 class="mb-2 text-sm font-semibold">{{ __('Balans sətirləri') }} — {{ $yearLabels[$year] ?? $year }}</h3>
        <div class="tbl-wrap rounded-lg border border-line">
            <table class="tbl">
                <thead><tr><th>{{ __('Köhnə') }}</th><th>{{ __('Balans sətri') }}</th><th>{{ __('Tərəf') }}</th><th class="num">{{ __('Məbləğ') }}</th></tr></thead>
                <tbody>
                @forelse ($current as $line)
                    <tr>
                        <td class="font-mono">{{ $line->legacy_code }}</td>
                        <td class="wrap">{{ $line->name }}</td>
                        <td>{{ $sideLabels[$line->side] ?? $line->side }}</td>
                        <td class="num {{ $line->amount < 0 ? 'neg' : '' }}">{{ Fmt::money($line->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-muted">{{ __('Bu tarixdə qalıq yoxdur') }}</td></tr>
                @endforelse
                </tbody>
                @if ($current->count() > 1)
                    <tfoot><tr><td colspan="3">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::money($total) }}</td></tr></tfoot>
                @endif
            </table>
        </div>
    </section>

    @if ($byYear->count() > 1)
        <section>
            <h3 class="mb-2 text-sm font-semibold">{{ __('Tarixlər üzrə dəyişmə') }}</h3>
            <div class="tbl-wrap rounded-lg border border-line">
                <table class="tbl">
                    <thead><tr><th>{{ __('Tarix') }}</th><th class="num">{{ __('Qalıq') }}</th><th class="num">{{ __('Dəyişmə') }}</th></tr></thead>
                    <tbody>
                    @php $years = $byYear->keys()->sort()->values(); @endphp
                    @foreach ($years->reverse() as $i => $y)
                        @php $prev = $years->search($y) > 0 ? $byYear[$years[$years->search($y) - 1]] : null; $diff = $prev === null ? null : $byYear[$y] - $prev; @endphp
                        <tr @class(['font-semibold' => $y === $year])>
                            <td>{{ $yearLabels[$y] ?? $y }}</td>
                            <td class="num {{ $byYear[$y] < 0 ? 'neg' : '' }}">{{ Fmt::money($byYear[$y]) }}</td>
                            <td class="num {{ $diff !== null && $diff < 0 ? 'neg' : ($diff > 0 ? 'pos' : '') }}">{{ $diff === null ? '—' : ($diff > 0 ? '+' : '').Fmt::money($diff) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @foreach ($ledger as $code => $rows)
        @php $totals = $rows->whereNull('document'); @endphp
        <section>
            <h3 class="mb-2 text-sm font-semibold">{{ __('Hesab :code üzrə kontragentlər və sənədlər', ['code' => $code]) }}</h3>
            <div class="tbl-wrap max-h-96 rounded-lg border border-line">
                <table class="tbl">
                    <thead><tr><th>{{ __('Kontragent / sənəd') }}</th><th class="num">{{ __('Debet') }}</th><th class="num">{{ __('Kredit') }}</th></tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        <tr @class(['font-semibold' => $r->document === null])>
                            <td class="wrap {{ $r->document ? 'pl-8 text-xs text-muted' : '' }}">{{ $r->document ?? $r->counterparty }}</td>
                            <td class="num">{{ (float) $r->debit != 0 ? Fmt::money($r->debit) : '' }}</td>
                            <td class="num">{{ (float) $r->credit != 0 ? Fmt::money($r->credit) : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr><td>{{ __('Cəmi') }}</td><td class="num">{{ Fmt::money($totals->sum('debit')) }}</td><td class="num">{{ Fmt::money($totals->sum('credit')) }}</td></tr></tfoot>
                </table>
            </div>
        </section>
    @endforeach
    @if ($ledger->isEmpty() && $current->isNotEmpty())
        <div class="text-xs text-muted">{{ __('Faylda bu hesab üzrə ayrıca dövriyyə vərəqi yoxdur — məbləğ birbaşa BALANS vərəqindəndir.') }}</div>
    @endif
</div>
