@if (count($years) > 0)
    @php $yearLabels ??= []; @endphp
    <form method="GET" class="flex items-center gap-2 text-xs text-muted">
        @foreach (request()->except(['year']) as $k => $v)
            @if (is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
        @endforeach
        <label for="acc-year">{{ $yearLabels ? __('Tarix') : __('İl') }}</label>
        <select id="acc-year" name="year" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
            @foreach ($years as $y)<option value="{{ $y }}" @selected($y === $year)>{{ isset($yearLabels[$y]) ? $yearLabels[$y] : $y }}</option>@endforeach
        </select>
    </form>
@endif
@if ($year === null)
    <div class="card px-4 py-10 text-center text-sm text-muted">
        {{ __('Mühasibat faylı hələ yüklənməyib. Import səhifəsindən «Balans / MZ / Cash Flow» faylını (.xls və ya .xlsx) yükləyin.') }}
        @can('data')<div class="mt-3"><a href="{{ route('import.index') }}" class="btn btn-sm btn-primary">{{ __('Import') }}</a></div>@endcan
    </div>
@endif
