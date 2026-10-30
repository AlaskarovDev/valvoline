@php $s = $supplier; $v = fn (string $key, $default = '') => old($key, $s?->{$key} ?? $default); @endphp
<x-layouts.app :title="$title">
    <form method="POST" action="{{ $s ? route('suppliers.update', $s) : route('suppliers.store') }}" class="flex flex-col gap-4">
        @csrf
        @if ($s) @method('PUT') @endif
        <div><a href="{{ $s ? route('suppliers.show', $s) : route('suppliers.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a></div>
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ __('Təchizatçı kartı') }}</h2><div class="card-sub">{{ __('Valyuta — hesablaşma valyutası: mədaxil qaimələri və borc bu valyutada aparılır.') }}</div></div></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                @include('numbering._field', ['id' => 's-code', 'name' => 'code', 'label' => __('Kod'), 'key' => 'supplier', 'current' => $s?->code, 'max' => 64])
                <div class="sm:col-span-2"><label class="label" for="s-name">{{ __('Ad') }}</label><input id="s-name" name="name" required maxlength="255" class="input" value="{{ $v('name') }}"></div>
                <div><label class="label" for="s-voen">{{ __('VÖEN / vergi nömrəsi') }}</label><input id="s-voen" name="voen" maxlength="32" class="input font-mono" value="{{ $v('voen') }}"></div>
                <div><label class="label" for="s-country">{{ __('Ölkə') }}</label><input id="s-country" name="country" maxlength="64" class="input" value="{{ $v('country') }}" list="s-countries">
                    <datalist id="s-countries">@foreach (['Azərbaycan', 'Türkiyə', 'Rusiya', 'BƏƏ', 'Niderland', 'Almaniya', 'ABŞ', 'Çin'] as $c)<option value="{{ $c }}">@endforeach</datalist></div>
                <div><label class="label" for="s-cur">{{ __('Valyuta') }}</label><select id="s-cur" name="currency" class="select">@foreach ($currencies as $c)<option value="{{ $c }}" @selected($v('currency', 'AZN') === $c)>{{ $c }}</option>@endforeach</select></div>
                <div><label class="label" for="s-days">{{ __('Ödəniş müddəti (gün)') }}</label><input id="s-days" name="payment_days" type="number" min="0" max="365" class="input" value="{{ $v('payment_days') }}"></div>
                <div><label class="label" for="s-contact">{{ __('Əlaqə şəxsi') }}</label><input id="s-contact" name="contact_person" maxlength="255" class="input" value="{{ $v('contact_person') }}"></div>
                <div><label class="label" for="s-phone">{{ __('Telefon') }}</label><input id="s-phone" name="phone" type="tel" maxlength="32" class="input" value="{{ $v('phone') }}"></div>
                <div><label class="label" for="s-email">{{ __('E-poçt') }}</label><input id="s-email" name="email" type="email" maxlength="255" class="input" value="{{ $v('email') }}"></div>
                <div class="sm:col-span-2"><label class="label" for="s-address">{{ __('Ünvan') }}</label><input id="s-address" name="address" maxlength="255" class="input" value="{{ $v('address') }}"></div>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4" @checked($v('is_active', true))> {{ __('Aktiv') }}</label>
                <div class="sm:col-span-2 lg:col-span-4"><label class="label" for="s-note">{{ __('Qeyd') }}</label><textarea id="s-note" name="note" rows="2" maxlength="2000" class="input h-auto py-2">{{ $v('note') }}</textarea></div>
            </div>
        </section>
        <div class="flex justify-end gap-2">
            <a href="{{ $s ? route('suppliers.show', $s) : route('suppliers.index') }}" class="btn">{{ __('Ləğv et') }}</a>
            <button class="btn btn-primary">{{ $s ? __('Yadda saxla') : __('Təchizatçı yarat') }}</button>
        </div>
    </form>
</x-layouts.app>
