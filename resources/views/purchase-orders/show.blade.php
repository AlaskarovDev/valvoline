@php use App\Support\Fmt; $cur = $doc->currency; $labels = \App\Models\PurchaseOrder::statusLabels(); @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('purchase-orders.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ Fmt::date($doc->date) }}</span>
        <a href="{{ route('suppliers.show', $doc->supplier) }}" class="badge badge-primary">{{ $doc->supplier->name }} · {{ $doc->supplier->code }}</a>
        <span class="badge">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</span>
        @if ($doc->expected_date)<span @class(['badge', 'badge-warn' => in_array($status, ['open', 'partial'], true) && $doc->expected_date->lt(now()->startOfDay())])>{{ __('Gözlənilən tarix') }}: {{ Fmt::date($doc->expected_date) }}</span>@endif
        <span @class(['badge', 'badge-pos' => $status === 'received', 'badge-warn' => $status === 'partial', 'badge-neg' => $status === 'cancelled'])>{{ $labels[$status] }}</span>
        @foreach ($receipts as $r)<a href="{{ route('purchase-docs.show', $r->id) }}" @class(['badge badge-primary', 'line-through' => $r->cancelled_at])>{{ __('Mədaxil') }}: {{ $r->doc_no }}</a>@endforeach
        <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('purchase-orders.show', [$doc, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm"><x-icon name="print" size="14"/> {{ __('Çap et') }}</a>
            @if (in_array($status, ['open', 'partial'], true))
                <a href="{{ route('purchase-docs.create', ['type' => 'receipt', 'order' => $doc->id]) }}" class="btn btn-sm btn-primary"><x-icon name="truck" size="14"/> {{ __('Mədaxil qaiməsi yarat') }}</a>
            @endif
            @if (in_array($status, ['open', 'partial', 'closed'], true))
                <form method="POST" action="{{ route('purchase-orders.close', $doc) }}">@csrf
                    <button class="btn btn-sm">{{ $status === 'closed' ? __('Yenidən aç') : __('Qalığı bağla') }}</button></form>
            @endif
            @unless ($doc->cancelled_at)
                <x-cancel-button :action="route('purchase-orders.destroy', $doc)" :confirm="__('Alış sifarişi ləğv edilsin?')"/>
            @endunless
        </div>
    </div>
    <x-cancelled-banner :doc="$doc"/>
    @if ($doc->note)<div class="card px-4 py-3 text-sm"><span class="label">{{ __('Qeyd') }}</span>{{ $doc->note }}</div>@endif
    <section class="card overflow-hidden">
        <div class="tbl-wrap relative">
            <table class="tbl">
                <thead><tr><th>#</th><th>{{ __('Kod') }}</th><th>{{ __('Mal') }}</th><th class="num">{{ __('Sifariş') }}</th><th class="num">{{ __('Qəbul edilib') }}</th><th class="num">{{ __('Qalıq') }}</th>
                    <th class="num">{{ __('Qiymət') }} ({{ $cur }})</th><th class="num">{{ __('Məbləğ') }}</th></tr></thead>
                <tbody>
                    @foreach ($lines as $l)
                        @php $received = min((float) $l->qty, (float) ($progress[$l->product_id]['received'] ?? 0)); @endphp
                        <tr>
                            <td class="text-muted">{{ $l->row_no }}</td>
                            <td class="font-mono text-xs">{{ $l->product_code }}</td>
                            <td>{{ $l->product_name }}</td>
                            <td class="num">{{ Fmt::qty($l->qty) }}</td>
                            <td @class(['num', 'pos' => $received + 1e-6 >= $l->qty])>{{ Fmt::qty($received) }}</td>
                            <td @class(['num', 'warn font-semibold' => $l->qty - $received > 1e-6])>{{ Fmt::qty(max(0, $l->qty - $received)) }}</td>
                            <td class="num">{{ Fmt::number($l->price, 4) }}</td>
                            <td class="num">{{ Fmt::number($l->amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot><tr><td colspan="7">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::number($doc->total_amount, 2) }} {{ $cur }}</td></tr></tfoot>
            </table>
        </div>
    </section>
    <x-audit-history type="PurchaseOrder" :id="$doc->id" :no="$doc->doc_no"/>
</x-layouts.app>
