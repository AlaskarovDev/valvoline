@php
    use App\Support\Fmt;
    $cell = fn ($v) => $v === null || abs((float) $v) < 0.005 ? '' : Fmt::money($v, 2, false);
    $sections = ['income' => __('Gəlirlər'), 'payment' => __('Ödənişlər'), 'other' => __('Digər əməliyyat gəlir / xərcləri')];
    $sectionMonth = fn (string $section, string $m) => (float) ($sheetRows[$section] ?? collect())->sum(fn ($r) => $r->months[$m] ?? 0);
    $sectionTotal = fn (string $section) => (float) ($sheetRows[$section] ?? collect())->sum('total');
    $firstMonth = $months[0] ?? null;
    $lastMonth = $months[count($months) - 1] ?? null;
    $typeDot = ['expense' => 'var(--negative)', 'cogs' => 'var(--chart-2)', 'tax' => 'var(--warning)', 'financing' => 'var(--chart-5)',
                'capex' => 'var(--chart-3)', 'refund' => 'var(--faint)', 'revenue' => 'var(--positive)', 'fx' => 'var(--faint)', 'uncertain' => 'var(--warning)'];
@endphp
<section class="card overflow-hidden">
    <div class="card-head">
        <div>
            <h2 class="card-title">Cash Flow — {{ $year }}</h2>
            <div class="card-sub">{{ __('Mühasibat faylındakı görünüş. Rəngli nöqtə — ödənişin növü (üstünə gəlin); yalnız «Əməliyyat xərci» P&L-ə düşür.') }}</div>
        </div>
    </div>
    <div class="tbl-wrap max-h-[80vh]">
        <table class="cf-sheet">
            <thead>
            <tr>
                <th class="cf-name">CASH FLOW</th>
                @foreach ($months as $m)<th>{{ Fmt::period($m) }}</th>@endforeach
                <th>{{ __('Cəmi') }}</th>
            </tr>
            </thead>
            <tbody>
            <tr class="cf-band">
                <td class="cf-name">{{ __('Dövrün əvvəlinə qalıq') }}</td>
                @foreach ($months as $m)<td>{{ $cell($openingByMonth[$m] ?? null) }}</td>@endforeach
                <td>{{ $cell($firstMonth ? ($openingByMonth[$firstMonth] ?? null) : null) }}</td>
            </tr>

            @foreach ($sections as $section => $label)
                @continue(empty($sheetRows[$section]) || $sheetRows[$section]->isEmpty())
                <tr class="cf-section"><td class="cf-name">{{ $label }}</td><td colspan="{{ count($months) + 1 }}"></td></tr>
                @foreach ($sheetRows[$section] as $r)
                    <tr>
                        <td class="cf-name" title="{{ $typeLabels[$r->type] ?? $r->type }} — {{ $r->note }}">
                            <span class="cf-dot" style="background: {{ $typeDot[$r->type] ?? 'var(--faint)' }}"></span>{{ $r->counterparty }}
                        </td>
                        @foreach ($months as $m)<td>{{ $cell($r->months[$m] ?? null) }}</td>@endforeach
                        <td class="cf-total">{{ $cell($r->total) }}</td>
                    </tr>
                @endforeach
                <tr class="cf-sum">
                    <td class="cf-name">{{ __('Cəmi') }}: {{ mb_strtolower($label) }}</td>
                    @foreach ($months as $m)<td>{{ $cell($sectionMonth($section, $m)) }}</td>@endforeach
                    <td>{{ $cell($sectionTotal($section)) }}</td>
                </tr>
            @endforeach

            <tr class="cf-sum cf-net">
                <td class="cf-name">{{ __('Xalis pul axını') }}</td>
                @foreach ($months as $m)
                    @php $net = $sectionMonth('income', $m) - $sectionMonth('payment', $m) + $sectionMonth('other', $m); @endphp
                    <td class="{{ $net < 0 ? 'neg' : '' }}">{{ $cell($net) }}</td>
                @endforeach
                @php $netTotal = $sectionTotal('income') - $sectionTotal('payment') + $sectionTotal('other'); @endphp
                <td class="{{ $netTotal < 0 ? 'neg' : '' }}">{{ $cell($netTotal) }}</td>
            </tr>
            <tr class="cf-band">
                <td class="cf-name">{{ __('Dövrün sonuna qalıq') }}</td>
                @foreach ($months as $m)<td>{{ $cell($closingByMonth[$m] ?? null) }}</td>@endforeach
                <td>{{ $cell($lastMonth ? ($closingByMonth[$lastMonth] ?? null) : null) }}</td>
            </tr>
            </tbody>
        </table>
    </div>
    <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-line px-4 py-2.5 text-xs text-muted">
        @foreach (['revenue', 'expense', 'cogs', 'tax', 'financing', 'capex', 'refund', 'uncertain'] as $type)
            <span class="inline-flex items-center"><span class="cf-dot" style="background: {{ $typeDot[$type] }}"></span>{{ $typeLabels[$type] }}</span>
        @endforeach
    </div>
</section>
