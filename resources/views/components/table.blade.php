@props(['table', 'rows', 'compact' => false])
@php
    /** @var \App\Reports\Table $table */
    $footer = $table->footerValues();
    $sortKey = $table->sortKey();
    $render = fn ($row, $column) => $column['format'] ? $table->display($row, $column) : e($table->display($row, $column));
    // Telefon kartı: başlıq = keçidli ilk mətn sütunu (yoxdursa birinci sütun), sağ üst = birinci sütun (məs. tarix)
    $titleIndex = 0;
    foreach ($table->columns as $i => $column) {
        if ($column['link'] !== null && in_array($column['type'], ['text', 'int'], true)) {
            $titleIndex = $i;
            break;
        }
    }
    $metaIndex = $titleIndex !== 0 ? 0 : null;
    $sortable = array_values(array_filter($table->columns, fn ($c) => $c['sortable']));
@endphp
<section {{ $attributes->merge(['class' => 'card overflow-hidden']) }}>
    <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
        <div class="min-w-0">
            @if ($table->title)<h2 class="card-title">{{ $table->title }}</h2>@endif
            <div class="card-sub">
                {{ number_format($rows->total(), 0, ',', ' ') }} sətir
                @if ($table->subtitle) · {{ $table->subtitle }} @endif
            </div>
        </div>
        <div class="flex w-full flex-wrap items-center gap-1.5 no-print md:ml-auto md:w-auto">
            @if ($table->searchable)
                <form method="GET" class="relative min-w-0 flex-1 md:flex-none">
                    @foreach (request()->except([$table->param('q'), $table->param('page'), 'export', 'table']) as $k => $v)
                        @if (is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                    @endforeach
                    <label class="sr-only" for="{{ $table->param('q') }}">Axtarış</label>
                    <x-icon name="search" size="14" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"/>
                    <input id="{{ $table->param('q') }}" name="{{ $table->param('q') }}" value="{{ $table->search() }}" placeholder="Axtar…" class="input h-[34px] w-full pl-8 text-xs md:h-[30px] md:w-44">
                </form>
            @endif
            {{ $actions ?? '' }}
            <a href="{{ $table->exportUrl('xlsx') }}" class="btn btn-sm" title="Excel-ə export"><x-icon name="download" size="14"/> Excel</a>
            <a href="{{ $table->exportUrl('pdf') }}" class="btn btn-sm" title="PDF-ə export"><x-icon name="download" size="14"/> PDF</a>
            @if ($sortable)
                <label class="sr-only" for="{{ $table->param('sortsel') }}">Sıralama</label>
                <select id="{{ $table->param('sortsel') }}" class="select h-[34px] w-full text-xs md:hidden" onchange="if (this.value) window.location = this.value">
                    @foreach ($sortable as $column)
                        @foreach (['desc' => '↓', 'asc' => '↑'] as $dir => $arrow)
                            <option value="{{ $table->url([$table->param('sort') => $column['key'], $table->param('dir') => $dir, $table->param('page') => null]) }}"
                                @selected($sortKey === $column['key'] && $table->sortDir() === $dir)>Sırala: {{ $column['label'] }} {{ $arrow }}</option>
                        @endforeach
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    {{-- Telefon: hər sətir bir kart --}}
    <div class="divide-y divide-line md:hidden">
        @forelse ($rows as $row)
            @php $titleColumn = $table->columns[$titleIndex]; $href = $table->link($row, $titleColumn); @endphp
            <div class="m-card px-4 py-3 {{ $table->rowCss($row) }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 text-sm font-semibold">
                        @if ($href)<a href="{{ $href }}" class="text-primary-text hover:underline">{!! $render($row, $titleColumn) !!}</a>@else{!! $render($row, $titleColumn) !!}@endif
                    </div>
                    @if ($metaIndex !== null)
                        <div class="shrink-0 text-xs text-muted">{!! $render($row, $table->columns[$metaIndex]) !!}</div>
                    @endif
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2">
                    @foreach ($table->columns as $i => $column)
                        @continue($i === $titleIndex || $i === $metaIndex || ! $column['mobile'])
                        @php $cellHref = $table->link($row, $column); $cls = trim(preg_replace('/\bnum\b/', '', $table->cellClass($row, $column))); @endphp
                        <div @class(['min-w-0', 'col-span-2' => $column['wrap'] || ($column['type'] === 'text' && $column['format'] === null && mb_strlen((string) data_get($row, $column['key'])) > 22)])>
                            <dt class="text-[11px] leading-tight text-muted">{{ $column['label'] }}</dt>
                            <dd class="text-[13px] font-medium tabular-nums {{ $cls }} {{ $column['wrap'] ? '' : 'truncate' }}">
                                @if ($cellHref)<a href="{{ $cellHref }}" class="text-primary-text hover:underline" @if($column['modal']) data-modal @endif>{!! $render($row, $column) !!}</a>@else{!! $render($row, $column) !!}@endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @empty
            <div class="px-4 py-10 text-center text-sm text-muted">Məlumat yoxdur</div>
        @endforelse
        @if ($footer)
            <div class="bg-surface-2 px-4 py-3">
                <div class="text-sm font-semibold">Cəmi</div>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-2">
                    @foreach ($table->columns as $column)
                        @continue(! isset($footer[$column['key']]))
                        <div class="min-w-0">
                            <dt class="text-[11px] leading-tight text-muted">{{ $column['label'] }}</dt>
                            <dd class="truncate text-[13px] font-semibold tabular-nums">{{ \App\Reports\Table::formatValue($footer[$column['key']], $column['type']) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </div>

    {{-- Planşet / kompüter: cədvəl --}}
    <div class="tbl-wrap hidden max-h-[70vh] md:block">
        <table class="tbl">
            <thead>
            <tr>
                @foreach ($table->columns as $column)
                    @php $isNum = in_array($column['type'], ['money', 'qty', 'int', 'pct'], true); @endphp
                    <th @class(['num' => $isNum]) scope="col" @if($sortKey === $column['key']) aria-sort="{{ $table->sortDir() === 'asc' ? 'ascending' : 'descending' }}" @endif>
                        @if ($column['sortable'])
                            <a href="{{ $table->sortUrl($column['key']) }}" class="inline-flex items-center gap-1 hover:text-ink">
                                {{ $column['label'] }}
                                @if ($sortKey === $column['key'])<x-icon :name="$table->sortDir() === 'asc' ? 'up' : 'down'" size="11"/>@endif
                            </a>
                        @else
                            {{ $column['label'] }}
                        @endif
                    </th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $row)
                <tr class="{{ $table->rowCss($row) }}">
                    @foreach ($table->columns as $column)
                        @php $href = $table->link($row, $column); @endphp
                        <td class="{{ $table->cellClass($row, $column) }}">
                            @if ($href)
                                <a href="{{ $href }}" class="cell-link" @if($column['modal']) data-modal @endif>{!! $render($row, $column) !!}</a>
                            @else
                                {!! $render($row, $column) !!}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($table->columns) }}" class="py-10 text-center text-muted">Məlumat yoxdur</td></tr>
            @endforelse
            </tbody>
            @if ($footer)
                <tfoot>
                <tr>
                    @foreach ($table->columns as $i => $column)
                        @php $value = $footer[$column['key']] ?? null; @endphp
                        <td @class(['num' => in_array($column['type'], ['money', 'qty', 'int', 'pct'], true)])>
                            @if ($i === 0 && $value === null) Cəmi @elseif ($value !== null) {{ \App\Reports\Table::formatValue($value, $column['type']) }} @endif
                        </td>
                    @endforeach
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
    @if ($rows->hasPages())
        <div class="border-t border-line px-4 py-2.5 text-sm no-print">{{ $rows->onEachSide(1)->links() }}</div>
    @endif
</section>
