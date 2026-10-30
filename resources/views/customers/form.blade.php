@php
    $c = $customer;
    $v = fn (string $key, $default = '') => old($key, $c?->{$key} ?? $default);
    $canCredit = ! $managerLocked;
@endphp
<x-layouts.app :title="$title">
    <form method="POST" action="{{ $c ? route('customers.update', $c) : route('customers.store') }}" class="flex flex-col gap-4" x-data="{ status: @js($v('status', 'active')) }">
        @csrf
        @if ($c) @method('PUT') @endif
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $c ? route('customers.show', $c) : route('customers.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        </div>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Əsas məlumat') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                @include('numbering._field', ['id' => 'c-code', 'name' => 'code', 'label' => __('Kod'), 'key' => 'customer', 'current' => $c?->code, 'max' => 64])
                <div class="sm:col-span-2"><label class="label" for="c-name">{{ __('Ad') }}</label><input id="c-name" name="name" required maxlength="255" class="input" value="{{ $v('name') }}"></div>
                <div><label class="label" for="c-voen">{{ __('VÖEN') }}</label><input id="c-voen" name="voen" inputmode="numeric" maxlength="10" pattern="\d{10}" class="input font-mono" value="{{ $v('voen') }}" placeholder="1234567890"></div>
                <div>
                    <label class="label" for="c-manager">{{ __('Satış nümayəndəsi') }}</label>
                    <select id="c-manager" name="manager_id" class="select" @disabled($managerLocked)>
                        @unless ($managerLocked)<option value="">—</option>@endunless
                        @foreach ($managers as $m)<option value="{{ $m->id }}" @selected((int) $v('manager_id') === $m->id)>{{ $m->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="c-list">{{ __('Qiymət növü') }}</label>
                    <select id="c-list" name="price_list_id" class="select">
                        <option value="">{{ __('Əsas (standart)') }}</option>
                        @foreach ($priceLists as $l)<option value="{{ $l->id }}" @selected((int) $v('price_list_id') === $l->id)>{{ $l->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="c-region">{{ __('Region / marşrut') }}</label>
                    <input id="c-region" name="region" maxlength="64" class="input" list="c-regions" value="{{ $v('region') }}" placeholder="{{ __('Məs.: Bakı — Nəsimi') }}">
                    <datalist id="c-regions">@foreach ($regions as $r)<option value="{{ $r }}">@endforeach</datalist>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Əlaqə') }}</h2></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="c-contact">{{ __('Əlaqə şəxsi') }}</label><input id="c-contact" name="contact_person" maxlength="255" class="input" value="{{ $v('contact_person') }}"></div>
                <div><label class="label" for="c-phone">{{ __('Telefon') }}</label><input id="c-phone" name="phone" type="tel" maxlength="32" class="input" value="{{ $v('phone') }}" placeholder="+994 50 000 00 00"></div>
                <div><label class="label" for="c-email">{{ __('E-poçt') }}</label><input id="c-email" name="email" type="email" maxlength="255" class="input" value="{{ $v('email') }}"></div>
                <div><label class="label" for="c-address">{{ __('Ünvan') }}</label><input id="c-address" name="address" maxlength="255" class="input" value="{{ $v('address') }}"></div>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ __('Ödəniş və kredit') }}</h2>
                    <div class="card-sub">{{ __('Ödəniş müddəti fakturanın ödəniş tarixini və gecikmiş borcu təyin edir. Kredit limiti: borc + fakturalanmamış sifarişlər bu məbləği keçə bilməz.') }}</div>
                </div>
            </div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="c-days">{{ __('Ödəniş müddəti (gün)') }}</label>
                    <input id="c-days" name="payment_days" type="number" min="0" max="365" class="input" value="{{ $v('payment_days') }}" placeholder="0 — {{ __('dərhal') }}" @disabled(! $canCredit)></div>
                <div><label class="label" for="c-limit">{{ __('Kredit limiti') }} (₼)</label>
                    <input id="c-limit" name="credit_limit" type="number" min="0" step="0.01" class="input" value="{{ $v('credit_limit') }}" placeholder="{{ __('boş — limitsiz') }}" @disabled(! $canCredit)></div>
                <div>
                    <label class="label" for="c-status">{{ __('Status') }}</label>
                    <select id="c-status" name="status" class="select" x-model="status" @disabled(! $canCredit)>
                        <option value="active">{{ __('Aktiv') }}</option>
                        <option value="blocked">{{ __('Bloklu (sifariş yazılmır)') }}</option>
                    </select>
                    @unless ($canCredit)<input type="hidden" name="status" value="{{ $v('status', 'active') }}">@endunless
                </div>
                <div x-show="status === 'blocked'" x-cloak><label class="label" for="c-block">{{ __('Bloklama səbəbi') }}</label>
                    <input id="c-block" name="block_reason" maxlength="255" class="input" value="{{ $v('block_reason') }}" :required="status === 'blocked'" @disabled(! $canCredit)></div>
                @unless ($canCredit)<p class="text-xs text-muted sm:col-span-2 lg:col-span-4">{{ __('Ödəniş müddəti, kredit limiti və bloklamanı yalnız admin dəyişir.') }}</p>@endunless
            </div>
        </section>

        <section class="card p-4">
            <label class="label" for="c-note">{{ __('Qeyd') }}</label>
            <textarea id="c-note" name="note" rows="2" maxlength="2000" class="input h-auto py-2">{{ $v('note') }}</textarea>
        </section>

        <div class="flex justify-end gap-2">
            <a href="{{ $c ? route('customers.show', $c) : route('customers.index') }}" class="btn">{{ __('Ləğv et') }}</a>
            <button class="btn btn-primary">{{ $c ? __('Yadda saxla') : __('Müştəri yarat') }}</button>
        </div>
    </form>
</x-layouts.app>
