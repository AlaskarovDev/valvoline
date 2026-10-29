@php use App\Support\Fmt; $print = $print ?? false; $count = \App\Models\StockDocument::isCount($doc->type); @endphp
<table @class(['tbl' => ! $print])>
    <thead><tr><th>#</th><th>{{ __('Kod') }}</th><th>{{ __('Malın adı') }}</th>
        @if ($count)<th class="num">{{ __('Sistemdə') }}</th><th class="num">{{ __('Sayılan') }}</th>@endif
        <th class="num">{{ $count ? __('Fərq') : __('Miqdar') }}</th><th class="num">{{ __('Vahid maya') }}</th><th class="num">{{ __('Dəyər') }}</th><th>{{ __('Qeyd') }}</th></tr></thead>
    <tbody>
        @foreach ($lines as $i => $l)
            <tr><td>{{ $i + 1 }}</td><td class="font-mono text-xs">{{ $l->product_code }}</td><td>{{ $l->product_name }}</td>
                @if ($count)<td class="num">{{ $l->system_qty !== null ? Fmt::qty($l->system_qty) : '—' }}</td><td class="num">{{ $l->counted_qty !== null ? Fmt::qty($l->counted_qty) : '—' }}</td>@endif
                <td class="num font-semibold {{ $sign > 0 ? 'pos' : 'neg' }}">{{ $sign > 0 ? '+' : '−' }}{{ Fmt::qty($l->qty) }}</td>
                <td class="num">{{ Fmt::money($l->cost) }}</td><td class="num">{{ Fmt::money($l->qty * $l->cost) }}</td><td>{{ $l->note }}</td></tr>
        @endforeach
    </tbody>
    <tfoot><tr><td colspan="{{ $count ? 5 : 3 }}">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::qty($lines->sum('qty')) }}</td><td></td><td class="num">{{ Fmt::money($lines->sum(fn ($l) => $l->qty * $l->cost)) }}</td><td></td></tr></tfoot>
</table>
