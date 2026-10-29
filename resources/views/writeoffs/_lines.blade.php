@php use App\Support\Fmt; $print = $print ?? false; @endphp
<section @class(['card overflow-hidden' => ! $print])>
    <div @class(['tbl-wrap' => ! $print])>
        <table @class(['tbl' => ! $print])>
            <thead><tr><th>#</th><th>{{ __('Kod') }}</th><th>{{ __('Malın adı') }}</th><th>{{ __('Partiya') }}</th><th>{{ __('Son istifadə tarixi') }}</th><th class="num">{{ __('Miqdar') }}</th><th class="num">{{ __('Vahid maya') }}</th><th class="num">{{ __('Dəyər') }}</th></tr></thead>
            <tbody>
                @foreach ($lines as $i => $l)
                    <tr><td>{{ $i + 1 }}</td><td class="font-mono text-xs">{{ $l->product_code }}</td><td>{{ $l->product_name }}</td><td>{{ $l->batch_no ?? '—' }}</td><td>{{ Fmt::date($l->expiry_date) }}</td>
                        <td class="num">{{ Fmt::qty($l->qty) }}</td><td class="num">{{ $l->unit_cost !== null ? Fmt::money($l->unit_cost) : '—' }}</td><td class="num">{{ Fmt::money($l->qty * ($l->unit_cost ?? 0)) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="5">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::qty($lines->sum('qty')) }}</td><td></td><td class="num">{{ Fmt::money($lines->sum(fn ($l) => $l->qty * ($l->unit_cost ?? 0))) }}</td></tr></tfoot>
        </table>
    </div>
</section>
