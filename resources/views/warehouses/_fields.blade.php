@php $id = $w?->id ?? 'new'; @endphp
<div><label class="label" for="wh-name-{{ $id }}">{{ __('Ad') }}</label><input id="wh-name-{{ $id }}" name="name" required class="input" value="{{ $w?->name }}"></div>
<div><label class="label" for="wh-code-{{ $id }}">{{ __('Kod') }}</label><input id="wh-code-{{ $id }}" name="code" maxlength="16" class="input font-mono" value="{{ $w?->code }}" placeholder="{{ __('avtomatik') }}"></div>
<div><label class="label" for="wh-type-{{ $id }}">{{ __('Növ') }}</label>
    <select id="wh-type-{{ $id }}" name="type" class="select" @disabled($w?->is_main)>
        @foreach (['main' => __('Əsas anbar'), 'van' => __('Səyyar maşın'), 'regional' => __('Regional anbar'), 'quarantine' => __('Karantin')] as $k => $l)
            @continue($k === 'main' && ! $w?->is_main)
            <option value="{{ $k }}" @selected(($w?->type ?? 'van') === $k)>{{ $l }}</option>
        @endforeach
    </select>
    @if ($w?->is_main)<input type="hidden" name="type" value="main">@endif</div>
<div><label class="label" for="wh-resp-{{ $id }}">{{ __('Məsul / sürücü') }}</label><input id="wh-resp-{{ $id }}" name="responsible" class="input" value="{{ $w?->responsible }}"></div>
<div><label class="label" for="wh-plate-{{ $id }}">{{ __('Maşın nömrəsi') }}</label><input id="wh-plate-{{ $id }}" name="vehicle_plate" maxlength="20" class="input" value="{{ $w?->vehicle_plate }}"></div>
<div><label class="label" for="wh-mgr-{{ $id }}">{{ __('Satış nümayəndəsi') }}</label>
    <select id="wh-mgr-{{ $id }}" name="manager_id" class="select"><option value="">—</option>@foreach ($managers as $m)<option value="{{ $m->id }}" @selected($w?->manager_id === $m->id)>{{ $m->name }}</option>@endforeach</select></div>
<div><label class="label" for="wh-addr-{{ $id }}">{{ __('Ünvan') }}</label><input id="wh-addr-{{ $id }}" name="address" class="input" value="{{ $w?->address }}"></div>
<div class="flex items-end"><label class="flex h-9 items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4" @checked($w?->is_active ?? true) @disabled($w?->is_main)> {{ __('Aktiv') }}</label></div>
