@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <form method="GET" class="card flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-56 flex-1"><label class="label" for="s-supplier">{{ __('Təchizatçı') }}</label>
            <select id="s-supplier" name="supplier" class="select" onchange="this.form.submit()">
                <option value="">— {{ __('bütün mallar (təchizatçısız)') }} —</option>
                @foreach ($suppliers as $s)<option value="{{ $s->id }}" @selected($supplier?->id === $s->id)>{{ $s->name }} · {{ $s->currency }}</option>@endforeach
            </select></div>
        <div><label class="label" for="s-cover">{{ __('Neçə günlük alınsın') }}</label><input id="s-cover" type="number" name="cover" min="1" max="365" value="{{ $cover }}" class="input w-28"></div>
        <label class="flex items-center gap-2 pb-2 text-sm"><input type="checkbox" name="all" value="1" class="size-4" @checked($all)> {{ $supplier ? __('bütün mallar') : __('təklifi olmayanlar da') }}</label>
        <button class="btn">{{ __('Hesabla') }}</button>
    </form>
    <div class="card px-4 py-3 text-sm text-muted">
        {{ __('Təklif: sərbəst qalıq (stok − rezerv + yoldakı sifariş) sifariş nöqtəsindən aşağıdırsa, :lead günlük çatdırılma + :cover günlük satış + ehtiyat qədər alınır. Ehtiyat — malın minimum stoku, yoxdursa 14 günlük satış; maksimum stok aşılmır. Gündəlik satış — son 90 günün ortası.', ['lead' => $lead, 'cover' => $cover]) }}
        @if ($supplier) {{ __('Çatdırılma müddətini təchizatçı kartında dəyişə bilərsiniz.') }} <a href="{{ route('suppliers.edit', $supplier) }}" class="text-primary-text hover:underline">{{ $supplier->name }}</a>@endif
    </div>

    <form method="POST" action="{{ route('purchase-orders.store') }}" class="card overflow-hidden" x-data="{ rows: @js($rows->map(fn ($r) => ['qty' => $r->suggested, 'price' => $r->price ?? 0])->values()) }">
        @csrf
        <input type="hidden" name="date" value="{{ now()->toDateString() }}">
        <div class="card-head"><div><h2 class="card-title">{{ __('Mallar') }}</h2><div class="card-sub">{{ $rows->isEmpty() ? __('Sifariş lazım olan mal yoxdur.') : __(':count mal', ['count' => $rows->count()]) }}</div></div></div>
        <div class="tbl-wrap relative">
            <table class="tbl">
                <thead><tr><th>{{ __('Kod') }}</th><th>{{ __('Mal') }}</th><th class="num">{{ __('Stok') }}</th><th class="num">{{ __('Rezerv') }}</th><th class="num">{{ __('Yolda') }}</th>
                    <th class="num">{{ __('Gündəlik satış') }}</th><th class="num">{{ __('Neçə günə çatır') }}</th><th class="num">{{ __('Sifariş miqdarı') }}</th><th class="num">{{ __('Qiymət') }}@if ($supplier) ({{ $supplier->currency }})@endif</th></tr></thead>
                <tbody>
                    @foreach ($rows as $i => $r)
                        <tr>
                            <td class="font-mono text-xs">{{ $r->code }}<input type="hidden" name="lines[{{ $i }}][product_id]" value="{{ $r->id }}"></td>
                            <td>{{ $r->name }}</td>
                            <td @class(['num', 'neg' => $r->stock < 0])>{{ Fmt::qty($r->stock) }}</td>
                            <td class="num text-muted">{{ Fmt::qty($r->reserved) }}</td>
                            <td class="num text-muted">{{ Fmt::qty($r->on_order) }}</td>
                            <td class="num">{{ Fmt::number($r->daily, 2) }}</td>
                            <td @class(['num', 'neg font-semibold' => $r->days_left !== null && $r->days_left < $lead, 'warn' => $r->days_left !== null && $r->days_left >= $lead && $r->suggested > 0])>{{ $r->days_left ?? '—' }}</td>
                            <td class="num"><label class="sr-only" for="q-{{ $i }}">{{ __('Sifariş miqdarı') }}</label>
                                <input id="q-{{ $i }}" type="number" step="any" min="0" name="lines[{{ $i }}][qty]" x-model.number="rows[{{ $i }}].qty" class="input w-24 text-right"></td>
                            <td class="num"><label class="sr-only" for="p-{{ $i }}">{{ __('Qiymət') }}</label>
                                <input id="p-{{ $i }}" type="number" step="any" min="0" name="lines[{{ $i }}][price]" x-model.number="rows[{{ $i }}].price" class="input w-24 text-right"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($rows->isNotEmpty())
            <div class="flex flex-wrap items-end gap-3 border-t border-line p-4">
                @if ($supplier)
                    <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
                    <div><label class="label" for="o-wh">{{ __('Anbar') }}</label><select id="o-wh" name="warehouse_id" class="select">@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
                    <div><label class="label" for="o-exp">{{ __('Gözlənilən tarix') }}</label><input id="o-exp" type="date" name="expected_date" value="{{ now()->addDays($lead)->toDateString() }}" class="input"></div>
                    <div class="min-w-48 flex-1"><label class="label" for="o-note">{{ __('Qeyd') }}</label><input id="o-note" name="note" maxlength="2000" class="input"></div>
                    <div class="text-sm">{{ __('Cəmi') }}: <strong x-text="fmt.number(rows.reduce((s, r) => s + (+r.qty || 0) * (+r.price || 0), 0), 2)"></strong> {{ $supplier->currency }}</div>
                    <button class="btn btn-primary">{{ __('Alış sifarişi yarat') }}</button>
                @else
                    <span class="text-sm text-muted">{{ __('Sifariş yaratmaq üçün yuxarıda təchizatçı seçin.') }}</span>
                @endif
            </div>
        @endif
    </form>
</x-layouts.app>
