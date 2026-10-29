@php use App\Support\Fmt; $t = $transfer; $open = in_array($t->status, ['sent', 'partial'], true); @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('transfers.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        @include('transfers._status', ['status' => $t->status, 'labels' => $statuses])
        <span class="badge">{{ Fmt::date($t->date) }}</span>
        <span class="badge badge-primary">{{ $t->from->name }} → {{ $t->to->name }}</span>
        <span class="badge">{{ $reasons[$t->reason] ?? $t->reason }}</span>
        @if ($t->driver || $t->vehicle_plate)<span class="badge">{{ trim($t->driver.' '.$t->vehicle_plate) }}</span>@endif
        @foreach ($writeOffs as $w)<a href="{{ route('write-offs.show', $w->id) }}" class="badge badge-neg">{{ __('Silinmə aktı') }} {{ $w->doc_no }}</a>@endforeach
        <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('transfers.show', [$t, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm"><x-icon name="print" size="14"/> {{ __('Yük qaiməsi') }}</a>
            <a href="{{ route('transfers.create', ['copy' => $t->id]) }}" class="btn btn-sm"><x-icon name="plus" size="14"/> {{ __('Kopyala') }}</a>
            @if ($t->status === 'draft')
                <a href="{{ route('transfers.edit', $t) }}" class="btn btn-sm"><x-icon name="edit" size="14"/> {{ __('Düzəliş et') }}</a>
                <form method="POST" action="{{ route('transfers.send', $t) }}">@csrf<button class="btn btn-sm"><x-icon name="send" size="14"/> {{ __('Göndər') }}</button></form>
                <form method="POST" action="{{ route('transfers.send', $t) }}">@csrf<input type="hidden" name="receive" value="1"><button class="btn btn-sm btn-primary"><x-icon name="truck" size="14"/> {{ __('Göndər və qəbul et') }}</button></form>
            @endif
            @if ($t->status === 'draft' || $t->status === 'sent')
                <form method="POST" action="{{ route('transfers.cancel', $t) }}" onsubmit="return confirm(@js(__('Transfer ləğv edilsin? Göndərilibsə mal mənbə anbara qayıdacaq.')))">@csrf<button class="btn btn-sm btn-danger">{{ __('Ləğv et') }}</button></form>
            @endif
        </div>
    </div>

    @if ($open)
        <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--warning)">{{ __('Mal yoldadır («Yolda (tranzit)» anbarında). :to anbarı faktiki miqdarı yazıb qəbul etməlidir; çatmayan hissə yolda qalır və sonradan qəbul edilə və ya kəsir kimi silinə bilər.', ['to' => $t->to->name]) }}</div>
    @endif

    <form method="POST" action="{{ route('transfers.receive', $t) }}">
        @csrf
        <section class="card overflow-hidden">
            <div class="card-head"><h2 class="card-title">{{ __('Mal sətirləri') }}</h2></div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr>
                        <th>#</th><th>{{ __('Kod') }}</th><th>{{ __('Mal') }}</th><th class="num">{{ __('Göndərilən') }}</th><th class="num">{{ __('Qəbul edilən') }}</th>
                        <th class="num">{{ __('Kəsir (silinib)') }}</th><th class="num">{{ __('Yolda') }}</th>
                        @if ($t->status === 'draft')<th class="num">{{ __('Sərbəst (mənbə)') }}</th>@endif
                        <th class="num">{{ __('Vahid maya') }}</th><th class="num">{{ __('Dəyər') }}</th>
                        @if ($open)<th class="num">{{ __('İndi qəbul') }}</th><th>{{ __('Fərq qeydi') }}</th>@endif
                    </tr></thead>
                    <tbody>
                        @foreach ($lines as $i => $l)
                            <tr @class(['row-neg' => $t->status === 'draft' && $l->qty > ($available[$l->product_id] ?? 0) + 1e-6])>
                                <td class="text-muted">{{ $i + 1 }}</td>
                                <td class="font-mono text-xs"><a href="{{ route('products.show', $l->product_id) }}" class="text-primary-text hover:underline">{{ $l->product_code }}</a></td>
                                <td class="wrap">{{ $l->product_name }}@if ($l->discrepancy_note)<div class="text-xs text-muted">{{ $l->discrepancy_note }}</div>@endif</td>
                                <td class="num">{{ Fmt::qty($l->qty) }}</td>
                                <td class="num">{{ $l->received_qty !== null ? Fmt::qty($l->received_qty) : '—' }}</td>
                                <td class="num {{ $l->written_off_qty > 0 ? 'neg' : 'text-muted' }}">{{ $l->written_off_qty > 0 ? Fmt::qty($l->written_off_qty) : '—' }}</td>
                                <td class="num {{ $l->open > 0 && $t->status !== 'draft' && $t->status !== 'cancelled' ? 'warn font-semibold' : 'text-muted' }}">{{ $t->status === 'draft' || $t->status === 'cancelled' ? '—' : Fmt::qty($l->open) }}</td>
                                @if ($t->status === 'draft')<td class="num">{{ Fmt::qty($available[$l->product_id] ?? 0) }}</td>@endif
                                <td class="num text-muted">{{ $l->moved_cost !== null ? Fmt::money($l->moved_cost) : '…' }}</td>
                                <td class="num">{{ $l->moved_cost !== null ? Fmt::money($l->moved_cost * $l->qty) : '…' }}</td>
                                @if ($open)
                                    <td class="num">@if ($l->open > 0)<input type="number" step="any" min="0" max="{{ $l->open }}" name="received[{{ $l->id }}]" value="{{ $l->open + 0 }}" class="input h-8 w-24 text-right" aria-label="{{ __('İndi qəbul') }} {{ $l->product_code }}">@endif</td>
                                    <td>@if ($l->open > 0)<input name="notes[{{ $l->id }}]" maxlength="255" class="input h-8 w-48" placeholder="{{ __('məs. 2 ədəd zədəli') }}" aria-label="{{ __('Fərq qeydi') }} {{ $l->product_code }}">@endif</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td colspan="3">{{ __('Cəmi') }}</td><td class="num">{{ Fmt::qty($lines->sum('qty')) }}</td><td class="num">{{ Fmt::qty($lines->sum('received_qty')) }}</td><td class="num">{{ Fmt::qty($lines->sum('written_off_qty')) }}</td><td class="num">{{ $t->status === 'draft' ? '' : Fmt::qty($lines->sum('open')) }}</td>
                        @if ($t->status === 'draft')<td></td>@endif<td></td><td class="num">{{ Fmt::money($lines->sum(fn ($l) => ($l->moved_cost ?? 0) * $l->qty)) }}</td>@if ($open)<td></td><td></td>@endif</tr></tfoot>
                </table>
            </div>
            @if ($open)
                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line p-4">
                    @if ($t->status === 'partial')
                        <button form="shortage-form" class="btn btn-danger" onclick="return confirm(@js(__('Yolda qalan fərq kəsir kimi silinsin? Silinmə aktı yaradılacaq.')))">{{ __('Fərqi kəsir kimi sil') }}</button>
                    @endif
                    <button class="btn btn-primary"><x-icon name="truck" size="14"/> {{ __('Qəbul et') }}</button>
                </div>
            @endif
        </section>
    </form>
    @if ($t->status === 'partial')<form id="shortage-form" method="POST" action="{{ route('transfers.shortage', $t) }}">@csrf</form>@endif

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Tarixçə') }}</h2></div>
        <ul class="flex flex-col gap-1 p-4 text-sm">
            <li>{{ __('Yaradılıb') }}: {{ $t->created_at?->format('d.m.Y H:i') }} · {{ $t->creator?->name ?? '—' }}</li>
            @if ($t->sent_at)<li>{{ __('Göndərilib') }}: {{ $t->sent_at->format('d.m.Y H:i') }} · {{ $t->sender?->name ?? '—' }} ({{ __('stok tarixi') }} {{ Fmt::date($t->sent_date) }})</li>@endif
            @if ($t->received_at)<li>{{ __('Qəbul edilib') }}: {{ $t->received_at->format('d.m.Y H:i') }} · {{ $t->receiver?->name ?? '—' }} ({{ __('stok tarixi') }} {{ Fmt::date($t->received_date) }})</li>@endif
            @if ($t->cancelled_at)<li class="neg">{{ __('Ləğv edilib') }}: {{ $t->cancelled_at->format('d.m.Y H:i') }} @if ($t->cancel_reason) · {{ $t->cancel_reason }} @endif</li>@endif
            @if ($t->note)<li class="text-muted">{{ __('Qeyd') }}: {{ $t->note }}</li>@endif
        </ul>
    </section>
</x-layouts.app>
