@php use App\Support\Fmt; @endphp
<div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="text-base font-semibold" data-modal-title>{{ $name }}</h2>
        <div class="text-xs text-muted">{{ $periodLabel }} · {{ $direction }} · yalnız «Xərc» tipli, mənfəətə təsir edən əməliyyatlar</div>
    </div>
    <div class="shrink-0 text-right">
        <div class="text-lg font-bold tabular-nums">{{ Fmt::money($total) }}</div>
        <div class="text-xs text-muted">{{ $rows->count() }} əməliyyat</div>
    </div>
</div>

@if ($bySub->count() > 1)
    <div class="flex flex-wrap gap-1.5 border-b border-line px-5 py-3">
        @foreach ($bySub as $sub => $info)
            <span class="badge">{{ $sub }} · {{ $info['count'] }} · <b class="tabular-nums">{{ Fmt::money($info['sum']) }}</b></span>
        @endforeach
    </div>
@endif

{{-- Telefon: kartlar --}}
<ul class="divide-y divide-line md:hidden">
    @foreach ($rows as $r)
        <li class="px-5 py-3 text-sm">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="font-medium">{{ $r->description ?: '—' }}</div>
                    <div class="text-xs text-muted">{{ Fmt::date($r->date) }}@if ($r->subcategory) · {{ $r->subcategory }}@endif · {{ $r->direction }}</div>
                </div>
                <div class="shrink-0 text-right tabular-nums">
                    @if ((float) $r->expense != 0)<div class="neg font-semibold">−{{ Fmt::money($r->expense) }}</div>@endif
                    @if ((float) $r->income != 0)<div class="pos font-semibold">+{{ Fmt::money($r->income) }}</div>@endif
                </div>
            </div>
            @if ($r->note)<div class="mt-1 text-xs text-muted">Qeyd: {{ $r->note }}</div>@endif
            @if ($r->original_row && $r->original_row !== $r->description)<div class="mt-1 text-xs text-faint">Excel: {{ $r->original_row }}</div>@endif
        </li>
    @endforeach
</ul>

{{-- Planşet / kompüter: cədvəl --}}
<div class="tbl-wrap hidden md:block">
    <table class="tbl">
        <thead>
        <tr>
            <th>Tarix</th><th>Alt kateqoriya</th><th>Təsvir</th><th class="num">Məxaric</th><th class="num">Mədaxil</th><th>İstiqamət</th><th>Qeyd / orijinal sətir</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td>{{ Fmt::date($r->date) }}</td>
                <td>{{ $r->subcategory ?: '—' }}</td>
                <td class="wrap">{{ $r->description ?: '—' }}</td>
                <td class="num {{ (float) $r->expense != 0 ? 'neg' : 'text-faint' }}">{{ Fmt::money($r->expense) }}</td>
                <td class="num {{ (float) $r->income != 0 ? 'pos' : 'text-faint' }}">{{ Fmt::money($r->income) }}</td>
                <td>{{ $r->direction }}</td>
                <td class="wrap text-xs text-muted">
                    {{ $r->note }}
                    @if ($r->original_row && $r->original_row !== $r->description)<div class="text-faint">Excel: {{ $r->original_row }}</div>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-8 text-center text-muted">Əməliyyat yoxdur</td></tr>
        @endforelse
        </tbody>
        <tfoot>
        <tr>
            <td colspan="3">Cəmi (məxaric − mədaxil)</td>
            <td class="num">{{ Fmt::money($rows->sum('expense')) }}</td>
            <td class="num">{{ Fmt::money($rows->sum('income')) }}</td>
            <td colspan="2" class="num">{{ Fmt::money($total) }}</td>
        </tr>
        </tfoot>
    </table>
</div>
<div class="border-t border-line bg-surface-2 px-5 py-3 text-xs text-muted md:hidden">
    Cəmi (məxaric − mədaxil): <b class="tabular-nums text-ink">{{ Fmt::money($total) }}</b>
</div>
