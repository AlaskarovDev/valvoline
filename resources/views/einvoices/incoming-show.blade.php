@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('einvoices.incoming') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Alış e-qaimələri') }}</a>
        @if ($invoice->status)<span @class(['badge', 'badge-neg' => $invoice->isVoid()])>{{ $invoice->status }}</span>@endif
        @if ($linked)
            <a href="{{ route('purchase-docs.show', $linked) }}" class="btn btn-sm btn-primary sm:ml-auto">{{ __('Mədaxil qaiməsi № :no', ['no' => $linked->doc_no]) }}</a>
        @endif
    </div>

    <section class="card">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-muted">{{ __('Göndərən') }}</dt><dd class="font-medium">{{ $invoice->sender_name }}</dd><dd class="text-xs text-muted">{{ __('VÖEN') }}: {{ $invoice->sender_tin }}</dd></div>
            <div><dt class="text-muted">{{ __('Tarix') }}</dt><dd>{{ Fmt::date($invoice->issued_at) }}</dd><dd class="text-xs text-muted">{{ $invoice->kind }}</dd></div>
            <div><dt class="text-muted">{{ __('Təchizatçı kartı') }}</dt><dd>@if ($invoice->supplier)<a class="text-primary-text hover:underline" href="{{ route('suppliers.show', $invoice->supplier) }}">{{ $invoice->supplier->name }}</a>@else<span class="text-muted">{{ __('VÖEN ilə tapılmadı — mədaxil yaradılanda kart açılacaq') }}</span>@endif</dd></div>
            <div><dt class="text-muted">{{ __('Məbləğ') }}</dt><dd>{{ __('ƏDV-siz') }} {{ Fmt::money($invoice->base_amount) }} · {{ __('ƏDV') }} {{ Fmt::money($invoice->vat_amount) }}</dd><dd class="font-semibold">{{ __('Yekun') }} {{ Fmt::money($invoice->total_amount) }}</dd></div>
            @if ($invoice->note)<div class="sm:col-span-2 lg:col-span-4"><dt class="text-muted">{{ __('Qeyd') }}</dt><dd>{{ $invoice->note }}</dd></div>@endif
        </dl>
    </section>

    <form method="POST" action="{{ route('einvoices.incoming.map', $invoice) }}" class="card">
        @csrf
        <div class="card-head"><div>
            <h2 class="card-title">{{ __('Mallar və uyğunluq') }}</h2>
            <div class="card-sub">{{ __('Təchizatçının hər malına bizim məhsulu seçin. Seçim yadda qalır — bu təchizatçının növbəti qaimələrində avtomatik tapılır. Əmsal: onların 1 vahidi bizim neçə vahiddir (məs. 1 qutu = 12 ədəd).') }}</div>
        </div></div>
        <div class="relative overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface-2 text-xs text-muted"><tr>
                    <th class="px-3 py-2 text-left">№</th><th class="px-3 py-2 text-left">{{ __('Mal adı (qaimədə)') }}</th><th class="px-3 py-2 text-right">{{ __('Miqdar') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Qiymət (ƏDV-siz)') }}</th><th class="px-3 py-2 text-right">{{ __('Cəmi') }}</th><th class="px-3 py-2 text-left">{{ __('Bizim məhsul') }}</th><th class="px-3 py-2 text-right">{{ __('Əmsal') }}</th>
                </tr></thead>
                <tbody class="divide-y divide-line">
                    @foreach ($items as $it)
                        <tr>
                            <td class="px-3 py-2">{{ $it['row'] }}</td>
                            <td class="px-3 py-2">{{ $it['name'] }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right">{{ Fmt::qty($it['qty']) }} {{ $it['unit'] }}</td>
                            <td class="px-3 py-2 text-right">{{ Fmt::money($it['price']) }}</td>
                            <td class="px-3 py-2 text-right">{{ Fmt::money($it['cost']) }}</td>
                            <td class="px-3 py-2">
                                <label class="sr-only" for="m-{{ $it['row'] }}">{{ __('Bizim məhsul') }}</label>
                                <select id="m-{{ $it['row'] }}" name="items[{{ $it['row'] }}][product_id]" @class(['select min-w-56', 'border-[var(--warning)]' => ! $it['product_id']])>
                                    <option value="">— {{ __('seçin') }} —</option>
                                    @foreach ($products as $p)<option value="{{ $p->id }}" @selected($it['product_id'] === $p->id)>{{ $p->code }} — {{ $p->name }}</option>@endforeach
                                </select>
                            </td>
                            <td class="px-3 py-2 text-right"><label class="sr-only" for="f-{{ $it['row'] }}">{{ __('Əmsal') }}</label><input id="f-{{ $it['row'] }}" type="number" step="any" min="0.0001" name="items[{{ $it['row'] }}][factor]" value="{{ $it['factor'] + 0 }}" class="input w-20 text-right"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-line p-4">
            @if ($linked)
                <span class="text-sm text-muted">{{ __('Bu e-qaimədən mədaxil artıq yaradılıb.') }}</span>
            @elseif ($invoice->isVoid())
                <span class="text-sm neg">{{ __('Ləğv edilmiş e-qaimədən mədaxil yaradılmır.') }}</span>
            @else
                <button class="btn btn-primary">{{ __('Uyğunluğu saxla və mədaxil qaiməsi yarat') }}</button>
            @endif
        </div>
    </form>
</x-layouts.app>
