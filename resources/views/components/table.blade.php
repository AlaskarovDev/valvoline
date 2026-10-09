@props(['table', 'rows', 'compact' => false])
@php
    /** @var \App\Reports\Table $table */
    $footer = $table->footerValues();
    $sortKey = $table->sortKey();
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
        <div class="ml-auto flex flex-wrap items-center gap-1.5 no-print">
            @if ($table->searchable)
                <form method="GET" class="relative">
                    @foreach (request()->except([$table->param('q'), $table->param('page'), 'export', 'table']) as $k => $v)
                        @if (is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                    @endforeach
                    <label class="sr-only" for="{{ $table->param('q') }}">Axtarış</label>
                    <x-icon name="search" size="14" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"/>
                    <input id="{{ $table->param('q') }}" name="{{ $table->param('q') }}" value="{{ $table->search() }}" placeholder="Axtar…" class="input h-[30px] w-44 pl-8 text-xs">
                </form>
            @endif
            {{ $actions ?? '' }}
            <a href="{{ $table->exportUrl('xlsx') }}" class="btn btn-sm" title="Excel-ə export"><x-icon name="download" size="14"/> Excel</a>
            <a href="{{ $table->exportUrl('pdf') }}" class="btn btn-sm" title="PDF-ə export"><x-icon name="download" size="14"/> PDF</a>
        </div>
    </div>
    <div class="tbl-wrap max-h-[70vh]">
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
                                <a href="{{ $href }}" class="cell-link">{!! $column['format'] ? $table->display($row, $column) : e($table->display($row, $column)) !!}</a>
                            @else
                                {!! $column['format'] ? $table->display($row, $column) : e($table->display($row, $column)) !!}
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
