@php
    use App\Support\Fmt;
    $isInvoice = $kind === 'invoice';
    $types = ['sale' => __('Satış'), 'gift' => __('Hədiyyə / pulsuz'), 'internal' => __('Daxili istifadə')];
    $listRoute = $isInvoice ? 'invoices.index' : 'deliveries.index';
    $showRoute = $isInvoice ? 'invoices.show' : 'deliveries.show';
@endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route($listRoute) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ Fmt::date($doc->date) }}</span>
        @if ($doc->customer)
            <a href="{{ route('customers.show', $doc->customer) }}" class="badge badge-primary">{{ $doc->customer->name }} · {{ $doc->customer->code }}</a>
        @else
            <span class="badge">{{ $doc->customer_name }}</span>
        @endif
        @if ($doc->manager)<span class="badge">{{ __('Menecer') }}: {{ $doc->manager->name }}</span>@endif
        @if ($doc->warehouse)<span class="badge">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</span>@endif
        @if ($doc->workplace)<span class="badge">{{ __('İş yeri') }}: {{ $doc->workplace->name }}</span>@endif
        @foreach ($related as [$type, $no, $url])
            <a href="{{ $url }}" class="badge badge-primary">{{ $type }} № {{ $no }}</a>
        @endforeach
        <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route($showRoute, [$doc, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><x-icon name="print" size="14"/> {{ __('Çap et') }}</a>
            @if ($canCancel)
                <form method="POST" action="{{ route($isInvoice ? 'invoices.destroy' : 'deliveries.destroy', $doc) }}"
                      onsubmit="return confirm(@js($isInvoice ? __('Faktura ləğv edilsin? Məbləğ müştəri borcundan çıxarılacaq.') : __('Təhvil-təslim ləğv edilsin? Mal anbara qaytarılacaq, sifariş yenidən rezervə düşəcək.')))">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger"><x-icon name="trash" size="14"/> {{ __('Ləğv et') }}</button>
                </form>
            @else
                <span class="text-xs text-muted">{{ __('Faktura bağlıdır — ləğv üçün əvvəlcə fakturanı ləğv edin.') }}</span>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Miqdar')" :value="Fmt::qty($totals['qty'])"/>
        @if ($isInvoice)
            <x-kpi :label="__('Məbləğ (endirimsiz)')" :value="Fmt::money($totals['gross'])"/>
            <x-kpi :label="__('Endirim')" :value="Fmt::money($totals['discount'])"/>
            <x-kpi :label="__('Yekun')" :value="Fmt::money($totals['amount'])" :meta="__('müştəri borcuna yazılıb')"/>
        @else
            <x-kpi :label="__('Məbləğ')" :value="Fmt::money($totals['amount'])"/>
            <x-kpi :label="__('Maya')" :value="Fmt::money($totals['cogs'])" :meta="__('real stokdan çıxıb')"/>
            <x-kpi :label="__('Qazanc')" :value="Fmt::money($totals['amount'] - $totals['cogs'])" :tone="$totals['amount'] - $totals['cogs'] < 0 ? 'neg' : 'pos'"/>
        @endif
    </div>
    @if ($doc->note)
        <div class="card px-4 py-3 text-sm"><span class="label">{{ __('Əlavə qeydlər') }}</span>{{ $doc->note }}</div>
    @endif

    <section class="card overflow-hidden">
        <div class="card-head"><h2 class="card-title">{{ __('Mal sətirləri') }}</h2></div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>#</th><th>{{ __('Kod') }}</th><th>{{ __('Mal') }}</th><th>{{ __('Tip') }}</th>
                        <th class="num">{{ __('Miqdar') }}</th><th class="num">{{ __('Qiymət') }}</th>
                        @if ($isInvoice)<th class="num">{{ __('Endirim') }}</th>@endif
                        <th class="num">{{ __('Yekun') }}</th><th class="num">{{ __('Maya') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $i => $l)
                        <tr>
                            <td class="text-muted">{{ $i + 1 }}</td>
                            <td class="font-mono text-xs">@if ($l->product_id)<a href="{{ route('movements.product', $l->product_id) }}" class="text-primary-text hover:underline">{{ $l->product_code }}</a>@else{{ $l->product_code }}@endif</td>
                            <td class="wrap">
                                {{ $l->product_name }}
                                @foreach ($campaigns[$l->sales_order_item_id ?? 0] ?? [] as $c)
                                    <div class="mt-1 text-xs text-muted">↳ <span class="badge badge-primary">{{ $c->campaign_code }}</span> {{ $c->campaign_name }} <span class="pos font-semibold">−{{ Fmt::money($c->amount) }}</span></div>
                                @endforeach
                            </td>
                            <td>{{ $types[$l->op_type] ?? $l->op_type }}</td>
                            <td class="num">{{ Fmt::qty($l->qty) }}</td>
                            <td class="num">{{ Fmt::money($l->price) }}</td>
                            @if ($isInvoice)<td class="num">{{ (float) $l->amount - (float) $l->net_amount > 0.004 ? Fmt::money((float) $l->amount - (float) $l->net_amount) : '—' }}</td>@endif
                            <td class="num font-semibold">{{ Fmt::money($l->net_amount) }}</td>
                            <td class="num text-muted">{{ $l->cogs !== null ? Fmt::money($l->cogs) : '…' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4">{{ __('Cəmi') }}</td>
                        <td class="num">{{ Fmt::qty($totals['qty']) }}</td><td></td>
                        @if ($isInvoice)<td class="num">{{ Fmt::money($totals['discount']) }}</td>@endif
                        <td class="num">{{ Fmt::money($totals['amount']) }}</td>
                        <td class="num">{{ Fmt::money($totals['cogs']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
    <p class="text-xs text-muted">{{ __('Yaradıb') }}: {{ $doc->creator?->name ?? '—' }} · {{ $doc->created_at?->format('d.m.Y H:i') }}</p>
</x-layouts.app>
