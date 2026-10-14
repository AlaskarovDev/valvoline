@php
    use App\Support\Fmt;
    $cell = fn ($v) => $v === null || abs((float) $v) < 0.005 ? '' : Fmt::money($v, 2, false);
    $dot = ['sales' => 'var(--positive)', 'cash' => 'var(--chart-4)', 'official' => 'var(--chart-2)', 'cogs' => 'var(--faint)'];
    $sumOf = fn (array $values) => array_sum($values);
    $lastColumn = $columns[count($columns) - 1] ?? null;
@endphp
<form id="pnl-openings" method="POST" action="{{ route('pnl.openings') }}">@csrf</form>

<section class="card overflow-hidden">
    <div class="card-head">
        <div>
            <h2 class="card-title">{{ __('Mənfəət-zərər — Excel görünüşü') }}</h2>
            <div class="card-sub">{{ __('Ayın əvvəlinə qalıq + gəlirlər − xərclər − təsisçiyə ödənişlər = ayın sonuna qalıq. Qalığı xanaya yazıb «Qalıqları saxla» basın; boş qalan ay əvvəlki ayın son qalığından davam edir.') }}</div>
        </div>
        <button form="pnl-openings" class="btn btn-sm btn-primary">{{ __('Qalıqları saxla') }}</button>
    </div>
    <div class="tbl-wrap max-h-[80vh]">
        <table class="cf-sheet">
            <thead>
            <tr>
                <th class="cf-name">{{ __('MƏNFƏƏT-ZƏRƏR') }}</th>
                @foreach ($columns as $c)<th>{{ Fmt::period($c) }}</th>@endforeach
                <th>{{ __('Cəmi') }}</th>
            </tr>
            </thead>
            <tbody>
            <tr class="cf-band">
                <td class="cf-name">{{ __('Dövrün əvvəlinə qalıq') }}</td>
                @foreach ($columns as $c)
                    @php $isManual = array_key_exists($c, $sheet['manual']); @endphp
                    <td>
                        <label class="sr-only" for="op-{{ $c }}">{{ __('Dövrün əvvəlinə qalıq') }} {{ Fmt::period($c) }}</label>
                        <input id="op-{{ $c }}" form="pnl-openings" name="opening[{{ $c }}]" type="number" step="0.01" inputmode="decimal"
                               value="{{ $isManual ? round($sheet['manual'][$c], 2) : '' }}" placeholder="{{ number_format($sheet['openings'][$c], 2, '.', '') }}"
                               title="{{ $isManual ? __('Əl ilə daxil edilib') : __('Əvvəlki ayın son qalığı — dəyişmək üçün yazın') }}"
                               @class(['cf-input', 'cf-input-manual' => $isManual])>
                    </td>
                @endforeach
                <td>{{ $columns ? $cell($sheet['openings'][$columns[0]]) : '' }}</td>
            </tr>

            @foreach ([[__('Gəlirlər'), $sheet['income'], $sheet['incomeTotal'], __('Cəmi gəlirlər')], [__('Xərclər'), $sheet['expense'], $sheet['expenseTotal'], __('Cəmi xərclər')]] as [$heading, $list, $total, $totalLabel])
                <tr class="cf-section"><td class="cf-name">{{ $heading }}</td><td colspan="{{ count($columns) + 1 }}"></td></tr>
                @foreach ($list as $row)
                    <tr>
                        <td class="cf-name" title="{{ $row['label'] }}"><span class="cf-dot" style="background: {{ $dot[$row['kind']] ?? 'var(--faint)' }}"></span>{{ $row['label'] }}</td>
                        @foreach ($columns as $c)<td>{{ $cell($row['values'][$c] ?? null) }}</td>@endforeach
                        <td class="cf-total">{{ $cell($sumOf($row['values'])) }}</td>
                    </tr>
                @endforeach
                <tr class="cf-sum">
                    <td class="cf-name">{{ $totalLabel }}</td>
                    @foreach ($columns as $c)<td>{{ $cell($total[$c] ?? 0) }}</td>@endforeach
                    <td>{{ $cell($sumOf($total)) }}</td>
                </tr>
            @endforeach

            <tr class="cf-sum cf-net">
                <td class="cf-name">{{ __('Xalis mənfəət') }}</td>
                @foreach ($columns as $c)<td class="{{ ($sheet['net'][$c] ?? 0) < 0 ? 'neg' : '' }}">{{ $cell($sheet['net'][$c] ?? 0) }}</td>@endforeach
                <td class="{{ $sumOf($sheet['net']) < 0 ? 'neg' : '' }}">{{ $cell($sumOf($sheet['net'])) }}</td>
            </tr>
            @if ($sheet['founder'])
                <tr class="cf-section"><td class="cf-name">{{ __('Təsisçiyə ödənişlər') }}</td><td colspan="{{ count($columns) + 1 }}"></td></tr>
                @foreach ($sheet['founder'] as $row)
                    <tr>
                        <td class="cf-name"><span class="cf-dot" style="background: var(--chart-5)"></span>{{ $row['label'] }}</td>
                        @foreach ($columns as $c)<td>{{ $cell(isset($row['values'][$c]) ? -$row['values'][$c] : null) }}</td>@endforeach
                        <td class="cf-total">{{ $cell(-$sumOf($row['values'])) }}</td>
                    </tr>
                @endforeach
            @endif
            <tr class="cf-band">
                <td class="cf-name">{{ __('Dövrün sonuna qalıq') }}</td>
                @foreach ($columns as $c)<td>{{ $cell($sheet['closings'][$c]) }}</td>@endforeach
                <td>{{ $lastColumn ? $cell($sheet['closings'][$lastColumn]) : '' }}</td>
            </tr>
            </tbody>
        </table>
    </div>
    <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-line px-4 py-2.5 text-xs text-muted">
        <span class="inline-flex items-center"><span class="cf-dot" style="background: var(--positive)"></span>{{ __('Satış faylı') }}</span>
        <span class="inline-flex items-center"><span class="cf-dot" style="background: var(--faint)"></span>{{ __('Maya (hərəkətli orta)') }}</span>
        <span class="inline-flex items-center"><span class="cf-dot" style="background: var(--chart-4)"></span>{{ __('Kassa (08 faylı)') }}</span>
        <span class="inline-flex items-center"><span class="cf-dot" style="background: var(--chart-2)"></span>{{ __('Rəsmi (mühasibat)') }}</span>
        <span class="inline-flex items-center"><span class="cf-dot" style="background: var(--chart-5)"></span>{{ __('Təsisçiyə ödəniş') }}</span>
    </div>
</section>
