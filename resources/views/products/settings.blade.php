@php
    $tabs = ['brands' => __('Markalar'), 'manufacturers' => __('İstehsalçılar'), 'classes' => __('Siniflər'), 'units' => __('Ölçü vahidləri'), 'price-lists' => __('Qiymət növləri')];
    $sets = [
        'brands' => [$brands, $brandCounts], 'manufacturers' => [$manufacturers, $manufacturerCounts], 'classes' => [$classes, $classCounts],
        'units' => [$units, $unitCounts], 'price-lists' => [$priceLists, $listCounts],
    ];
@endphp
<x-layouts.app :title="$title">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Kitabçalar') }}">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('product-settings.index', ['tab' => $key]) }}" @class(['btn btn-sm', 'btn-primary' => $tab === $key])>{{ $label }} <span class="badge">{{ $sets[$key][0]->count() }}</span></a>
        @endforeach
    </nav>

    @php [$items, $counts] = $sets[$tab]; @endphp
    <section class="card overflow-hidden">
        <div class="card-head"><h2 class="card-title">{{ $tabs[$tab] }}</h2></div>
        <form method="POST" action="{{ route('product-settings.store', $tab) }}" class="flex flex-wrap items-end gap-2 border-b border-line px-4 py-3">
            @csrf
            <div class="min-w-0 flex-1 sm:flex-none"><label class="label" for="s-name">{{ __('Ad') }}</label><input id="s-name" name="name" required class="input h-[34px] sm:w-64"></div>
            @if ($tab === 'manufacturers')<div><label class="label" for="s-country">{{ __('Ölkə') }}</label><input id="s-country" name="country" class="input h-[34px]"></div>@endif
            @if ($tab === 'units')<div><label class="label" for="s-code">{{ __('e-qaimə kodu') }}</label><input id="s-code" name="code" maxlength="16" class="input h-[34px] w-28"></div>@endif
            @if ($tab === 'price-lists')
                <div><label class="label" for="s-type">{{ __('Növ') }}</label><select id="s-type" name="type" class="select h-[34px]"><option value="sale">{{ __('Satış') }}</option><option value="purchase">{{ __('Alış') }}</option></select></div>
                <label class="flex h-[34px] items-center gap-1.5 text-sm"><input type="hidden" name="vat_included" value="0"><input type="checkbox" name="vat_included" value="1" checked class="size-4"> {{ __('ƏDV daxil') }}</label>
                <label class="flex h-[34px] items-center gap-1.5 text-sm"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" class="size-4"> {{ __('Əsas') }}</label>
            @endif
            <button class="btn btn-sm btn-primary h-[34px]"><x-icon name="plus" size="14"/> {{ __('Əlavə et') }}</button>
        </form>
        <ul class="divide-y divide-[var(--border)]">
            @forelse ($items as $item)
                <li class="flex flex-wrap items-center gap-2 px-4 py-2 text-sm" x-data="{ edit: false }">
                    <div class="min-w-0 flex-1" x-show="!edit">
                        <span class="font-medium">{{ $item->name }}</span>
                        @if ($tab === 'manufacturers' && $item->country)<span class="text-xs text-muted">· {{ $item->country }}</span>@endif
                        @if ($tab === 'units' && $item->code)<span class="text-xs text-muted">· {{ $item->code }}</span>@endif
                        @if ($tab === 'price-lists')
                            <span class="badge">{{ $item->type === 'sale' ? __('Satış') : __('Alış') }}</span>
                            <span class="badge">{{ $item->vat_included ? __('ƏDV daxil') : __('ƏDV xaric') }}</span>
                            @if ($item->is_default)<span class="badge badge-primary">{{ __('Əsas') }}</span>@endif
                            @if ($listCustomers[$item->id] ?? 0)<span class="text-xs text-muted">· {{ __(':n müştəri', ['n' => $listCustomers[$item->id]]) }}</span>@endif
                        @endif
                    </div>
                    <span class="text-xs text-muted" x-show="!edit">{{ $tab === 'price-lists' ? __(':n kart', ['n' => $counts[$item->id] ?? 0]) : __(':n məhsul', ['n' => $counts[$item->id] ?? 0]) }}</span>
                    <form x-show="edit" x-cloak method="POST" action="{{ route('product-settings.update', [$tab, $item->id]) }}" class="flex flex-1 flex-wrap items-center gap-2">
                        @csrf @method('PUT')
                        <input name="name" value="{{ $item->name }}" required class="input h-[30px] w-56" aria-label="{{ __('Ad') }}">
                        @if ($tab === 'manufacturers')<input name="country" value="{{ $item->country }}" class="input h-[30px] w-40" aria-label="{{ __('Ölkə') }}">@endif
                        @if ($tab === 'units')<input name="code" value="{{ $item->code }}" class="input h-[30px] w-24" aria-label="{{ __('e-qaimə kodu') }}">@endif
                        @if ($tab === 'price-lists')
                            <select name="type" class="select h-[30px] w-auto" aria-label="{{ __('Növ') }}"><option value="sale" @selected($item->type === 'sale')>{{ __('Satış') }}</option><option value="purchase" @selected($item->type === 'purchase')>{{ __('Alış') }}</option></select>
                            <label class="flex items-center gap-1 text-xs"><input type="hidden" name="vat_included" value="0"><input type="checkbox" name="vat_included" value="1" class="size-4" @checked($item->vat_included)> {{ __('ƏDV daxil') }}</label>
                            <label class="flex items-center gap-1 text-xs"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" class="size-4" @checked($item->is_default)> {{ __('Əsas') }}</label>
                            <label class="flex items-center gap-1 text-xs"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4" @checked($item->is_active)> {{ __('Aktiv') }}</label>
                        @endif
                        <button class="btn btn-sm btn-primary">{{ __('Yadda saxla') }}</button>
                    </form>
                    <button type="button" class="btn btn-sm px-2" @click="edit = !edit" aria-label="{{ __('Düzəliş et') }}"><x-icon name="edit" size="14"/></button>
                    <form method="POST" action="{{ route('product-settings.destroy', [$tab, $item->id]) }}" onsubmit="return confirm(@js(__('Silinsin?')))">@csrf @method('DELETE')
                        <button class="btn btn-sm btn-danger px-2" aria-label="{{ __('Sil') }}"><x-icon name="trash" size="14"/></button></form>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-sm text-muted">{{ __('Siyahı boşdur') }}</li>
            @endforelse
        </ul>
    </section>
</x-layouts.app>
