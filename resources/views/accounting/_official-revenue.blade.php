@php
    use App\Services\OfficialRevenueService as O;
    use App\Support\Fmt;
    $badge = [O::INCLUDED => 'badge-pos', O::EXTRA => 'badge-primary', O::NOT_REVENUE => '', O::PENDING => 'badge-warn'];
    $months = collect($payers)->flatMap(fn ($p) => array_keys($p->months))->unique()->sort()->values();
    $sum = fn (string $d) => collect($payers)->where('decision', $d)->sum('total');
@endphp
<section class="card overflow-hidden">
    <div class="card-head">
        <div>
            <h2 class="card-title">{{ __('Rəsmi gəlirlər — müştərilərin bank ödənişləri') }} · {{ $year }}</h2>
            <div class="card-sub">{{ __('Hər ödəyici sistemlə tutuşdurulur. Müştərinin satışı sistemdə varsa (bu il və ya əvvəlki illərdə), bank ödənişi həmin satışın pulu sayılır və P&L-ə təkrar yazılmır. Tapılmayanlar üçün qərarı siz verin.') }}</div>
        </div>
    </div>
    <div class="grid grid-cols-2 gap-px bg-line lg:grid-cols-4">
        @foreach ([O::INCLUDED, O::EXTRA, O::PENDING, O::NOT_REVENUE] as $d)
            <div class="bg-surface p-4">
                <div class="kpi-label"><span class="badge {{ $badge[$d] }}">{{ $decisionLabels[$d] }}</span></div>
                <div class="mt-1 text-lg font-semibold tabular-nums">{{ Fmt::money($sum($d)) }}</div>
            </div>
        @endforeach
    </div>
    <div class="tbl-wrap max-h-[75vh]">
        <table class="tbl">
            <thead><tr>
                <th class="sticky left-0 z-[2] min-w-56 bg-surface-2">{{ __('Bankda ödəyici') }}</th>
                <th class="num">{{ __('Cəmi') }}</th>
                <th class="min-w-56">{{ __('Sistemdə müştəri') }}</th>
                <th class="num">{{ __('Sistem: satış bu il / əvvəl') }}</th>
                <th class="min-w-72">{{ __('P&L-ə münasibət') }}</th>
                @foreach ($months as $m)<th class="num">{{ Fmt::period($m) }}</th>@endforeach
            </tr></thead>
            <tbody>
            @forelse ($payers as $i => $p)
                <tr>
                    <td class="sticky left-0 z-[1] whitespace-normal bg-surface font-medium">{{ $p->counterparty }}</td>
                    <td class="num font-semibold">{{ Fmt::money($p->total) }}</td>
                    <td class="wrap">
                        @if ($p->customer)
                            <a href="{{ route('customers.show', $p->customer->id) }}" class="cell-link">{{ $p->customer->name }}</a>
                            @if ($p->score < 0.8)<span class="text-xs text-muted">({{ __('ehtimal') }})</span>@endif
                        @else
                            <span class="text-faint">{{ __('tapılmadı') }}</span>
                        @endif
                    </td>
                    <td class="num text-xs">{{ $p->customer ? Fmt::money($p->year_sales, 0).' / '.Fmt::money($p->earlier_sales, 0) : '—' }}</td>
                    <td class="wrap">
                        <form method="POST" action="{{ route('accounting.revenue.decide') }}">
                            @csrf
                            <input type="hidden" name="normalized" value="{{ $p->normalized }}">
                            <input type="hidden" name="counterparty" value="{{ $p->counterparty }}">
                            <label class="sr-only" for="rd-{{ $i }}">{{ __('P&L-ə münasibət') }}</label>
                            <select id="rd-{{ $i }}" name="decision" class="select h-[30px] w-full text-xs" onchange="this.form.submit()">
                                <option value="auto" @selected(! $p->manual)>{{ __('Avtomatik') }}: {{ $decisionLabels[$p->auto] }}</option>
                                @foreach (O::DECISIONS as $d)
                                    <option value="{{ $d }}" @selected($p->manual && $p->decision === $d)>{{ $decisionLabels[$d] }}</option>
                                @endforeach
                            </select>
                        </form>
                        <div class="mt-1 text-xs text-muted">
                            <span class="badge {{ $badge[$p->decision] }}">{{ $decisionLabels[$p->decision] }}</span>
                            @if (! $p->manual) {{ $p->reason }}@else <span class="badge">{{ __('əl ilə') }}</span>@endif
                        </div>
                    </td>
                    @foreach ($months as $m)
                        <td class="num {{ empty($p->months[$m]) ? 'text-faint' : '' }}">{{ empty($p->months[$m]) ? '—' : Fmt::money($p->months[$m], 0, false) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ 5 + $months->count() }}" class="py-6 text-center text-muted">{{ __('Məlumat yoxdur') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="border-t border-line px-4 py-2.5 text-xs text-muted">
        {{ __('«Sistemdə olmayan satış» seçilən ödənişlər P&L-də «Rəsmi gəlir» sətrinə ay üzrə əlavə olunur (mayası məlum olmadığı üçün tam məbləğ mənfəətə düşür). Təsisçidən daxilolmalar və re-eksport alıcısı burada deyil.') }}
    </div>
</section>
