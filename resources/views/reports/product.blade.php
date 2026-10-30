@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('stock.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Anbar') }}</a>
        @if ($product->category)<span class="badge">{{ $product->category->name }}</span>@endif
        @if ($product->series)<span class="badge">Seriya: {{ $product->series }}</span>@endif
        @if ($product->group)<span class="badge badge-primary">Qrup: {{ $product->group->name }}</span>@endif
        @if ($product->pack_type)<span class="badge">{{ $product->pack_type }}</span>@endif
        <span class="badge">{{ Fmt::qty($product->volume_l) }} L · qutuda {{ $product->pack_qty ?? '—' }}</span>
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <x-kpi :label="__('Cari qalıq')" :value="__(':n ədəd', ['n' => Fmt::qty($product->stock_qty)])" :tone="$product->stock_qty < 0 ? 'neg' : null" :meta="__(':n litr', ['n' => Fmt::number($product->stock_qty * $product->volume_l)])"/>
        <x-kpi :label="__('Orta maya (hesablanmış)')" :value="Fmt::money($product->current_avg_cost)"
               :meta="__('fayldakı').': '.Fmt::money($product->file_avg_cost)" :tone="$product->cost_estimated ? 'warn' : null"/>
        <x-kpi :label="__('Anbar dəyəri')" :value="Fmt::money(max($product->stock_qty, 0) * (float) $product->current_avg_cost)"/>
        <x-kpi :label="__('Satış qiyməti')" :value="Fmt::money($product->sale_price)"
               :meta="$product->current_avg_cost > 0 ? __('marja').' '.Fmt::pct(($product->sale_price - $product->current_avg_cost) / max($product->sale_price, 0.0001)) : null"/>
        <x-kpi :label="__('Ümumi satış')" :value="Fmt::money($sold->amount ?? 0)" :meta="__(':n ədəd', ['n' => Fmt::qty($sold->qty ?? 0)]).' · '.__('qazanc').' '.Fmt::money($sold->profit ?? 0)"/>
    </div>

    @if ($estimatedLines->isNotEmpty())
        <section class="card border-l-4" style="border-left-color: var(--warning)">
            <div class="card-head">
                <div>
                    <h2 class="card-title"><span class="warn">⚠</span> {{ __('Mayası təxmini olan alış sətirləri') }}</h2>
                    <div class="card-sub">{{ __('Dəyişəndən sonra bütün maya, mənfəət və anbar dəyəri yenidən hesablanır.') }}</div>
                </div>
            </div>
            <div class="divide-y divide-line">
                @foreach ($estimatedLines as $line)
                    <form method="POST" action="{{ route('products.cost', $product) }}" class="flex flex-wrap items-end gap-3 px-4 py-3 text-sm">
                        @csrf @method('PUT')
                        <input type="hidden" name="line_id" value="{{ $line->id }}">
                        <div><div class="label">{{ __('Tarix / partiya') }}</div>{{ Fmt::date($line->date) }} · №{{ $line->batch_no }}</div>
                        <div><div class="label">{{ __('Miqdar') }}</div><span class="num">{{ Fmt::qty($line->qty) }}</span></div>
                        <div class="min-w-0 flex-1"><div class="label">{{ __('Qeyd') }}</div><span class="text-muted">{{ $line->note }}</span></div>
                        @can('products.manage')
                            <div>
                                <label class="label" for="cost-{{ $line->id }}">{{ __('Vahid maya, ₼') }}</label>
                                <input id="cost-{{ $line->id }}" name="unit_cost" type="number" step="0.0001" min="0" value="{{ (float) $line->unit_cost }}" class="input w-36 num">
                            </div>
                            <button class="btn btn-primary">{{ __('Yadda saxla və yenidən hesabla') }}</button>
                        @else
                            <div><div class="label">{{ __('Vahid maya') }}</div>{{ Fmt::money($line->unit_cost) }}</div>
                        @endcan
                    </form>
                @endforeach
            </div>
        </section>
    @endif

    <x-chart :title="__('Qalıq və orta maya dəyişməsi')" :spec="$chart" :height="300"/>

    <x-filters :filters="$filters" :show="[]"/>
    <x-table :table="$table" :rows="$rows"/>

    @if ($siblings->isNotEmpty())
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Eyni məhsul qrupunun digər kodları') }}</h2></div>
            <ul class="divide-y divide-line text-sm">
                @foreach ($siblings as $s)
                    <li class="flex items-center justify-between gap-3 px-4 py-2.5">
                        <a class="cell-link font-medium text-primary-text hover:underline" href="{{ route('movements.product', $s->id) }}">{{ $s->code }} — {{ $s->name }}</a>
                        <span class="text-muted">{{ $s->pack_type }} · {{ __('qalıq') }} <span class="num">{{ Fmt::qty($s->stock_qty) }}</span></span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-layouts.app>
