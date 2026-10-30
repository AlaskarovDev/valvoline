@php use App\Support\Fmt; $isAdmin = auth()->user()->can('products.manage'); @endphp
<x-layouts.app :title="$title">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Aktiv məhsul')" :value="Fmt::number($kpi['count'])" :meta="__(':n passiv', ['n' => $kpi['passive']])"/>
        <x-kpi :label="__('Stok dəyəri (maya)')" :value="Fmt::money($kpi['value'])" :meta="__('rezervdə :n', ['n' => Fmt::number($kpi['reserved'])])"/>
        <x-kpi :label="__('Az qalıb / bitib')" :value="Fmt::number($kpi['low']).' / '.Fmt::number($kpi['out'])" :tone="$kpi['low'] + $kpi['out'] > 0 ? 'warn' : null"
               :href="request()->fullUrlWithQuery(['state' => 'low', 'products_page' => null])"/>
        <x-kpi :label="__('Sifariş tövsiyəsi')" :value="Fmt::number($kpi['reorder'])" :meta="__(':n hərəkətsiz', ['n' => $kpi['dead']])"
               :href="request()->fullUrlWithQuery(['state' => 'reorder', 'products_page' => null])"/>
    </div>

    <section class="card">
        <form method="GET" class="flex flex-wrap items-end gap-2 px-4 py-3">
            <div>
                <label class="label" for="f-state">{{ __('Vəziyyət') }}</label>
                <select id="f-state" name="state" class="select h-[34px] text-xs" onchange="this.form.submit()">
                    <option value="active" @selected($state === 'active')>{{ __('Aktiv məhsullar') }}</option>
                    <option value="all" @selected($state === 'all')>{{ __('Hamısı') }}</option>
                    <option value="reorder" @selected($state === 'reorder')>{{ __('Sifariş tövsiyəsi') }}</option>
                    @foreach ($states as $key => $label)<option value="{{ $key }}" @selected($state === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            @foreach (['brand' => [__('Marka'), $filters['brands']], 'class' => [__('Sinif'), $filters['classes']], 'category' => [__('Kateqoriya'), $filters['categories']], 'manufacturer' => [__('İstehsalçı'), $filters['manufacturers']]] as $param => [$label, $list])
                @if ($list->isNotEmpty())
                    <div>
                        <label class="label" for="f-{{ $param }}">{{ $label }}</label>
                        <select id="f-{{ $param }}" name="{{ $param }}" class="select h-[34px] max-w-48 text-xs" onchange="this.form.submit()">
                            <option value="">{{ __('Hamısı') }}</option>
                            @foreach ($list as $item)<option value="{{ $item->id }}" @selected((string) request($param) === (string) $item->id)>{{ $item->name }}</option>@endforeach
                        </select>
                    </div>
                @endif
            @endforeach
            <div>
                <label class="label" for="f-wh">{{ __('Anbar') }}</label>
                <select id="f-wh" name="warehouse" class="select h-[34px] text-xs" onchange="this.form.submit()">
                    <option value="">{{ __('Bütün anbarlar') }}</option>
                    @foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($warehouseId === $w->id)>{{ $w->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="label" for="f-abc">ABC</label>
                <select id="f-abc" name="abc" class="select h-[34px] text-xs" onchange="this.form.submit()">
                    <option value="">{{ __('Hamısı') }}</option>
                    @foreach (['A', 'B', 'C'] as $c)<option value="{{ $c }}" @selected(request('abc') === $c)>{{ $c }}</option>@endforeach
                </select>
            </div>
            <a href="{{ route('products.index') }}" class="btn btn-sm h-[34px]">{{ __('Sıfırla') }}</a>
        </form>
    </section>

    <x-table :table="$table" :rows="$rows">
        <x-slot:actions>
            <a href="{{ route('products.labels', ['ids' => $rows->pluck('id')->all()]) }}" target="_blank" rel="noopener" class="btn btn-sm" title="{{ __('Bu səhifədəki məhsullar üçün barkod etiketi') }}"><x-icon name="print" size="14"/> {{ __('Etiket') }}</a>
            @if ($isAdmin)<a href="{{ route('products.create') }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Yeni məhsul') }}</a>@endif
        </x-slot:actions>
    </x-table>
</x-layouts.app>
