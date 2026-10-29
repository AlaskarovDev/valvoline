@php $id = $m?->id ?? 'new'; @endphp
<div><label class="label" for="rp-name-{{ $id }}">{{ __('Ad, soyad') }}</label><input id="rp-name-{{ $id }}" name="name" required class="input" value="{{ $m?->name }}"></div>
<div><label class="label" for="rp-code-{{ $id }}">{{ __('Kod') }}</label><input id="rp-code-{{ $id }}" name="code" maxlength="16" class="input font-mono" value="{{ $m?->code }}" placeholder="{{ __('avtomatik') }}"></div>
<div><label class="label" for="rp-phone-{{ $id }}">{{ __('Telefon') }}</label><input id="rp-phone-{{ $id }}" name="phone" maxlength="32" class="input" value="{{ $m?->phone }}"></div>
<div><label class="label" for="rp-role-{{ $id }}">{{ __('Rol') }}</label>
    <select id="rp-role-{{ $id }}" name="role" class="select"><option value="rep" @selected(($m?->role ?? 'rep') === 'rep')>{{ __('Satış nümayəndəsi') }}</option><option value="forwarder" @selected($m?->role === 'forwarder')>{{ __('Ekspeditor') }}</option></select></div>
<div><label class="label" for="rp-wp-{{ $id }}">{{ __('İş yeri') }}</label>
    <select id="rp-wp-{{ $id }}" name="workplace_id" class="select" required>@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected($m?->workplace_id === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
<div><label class="label" for="rp-wh-{{ $id }}">{{ __('Səyyar anbar (istəyə görə)') }}</label>
    <select id="rp-wh-{{ $id }}" name="warehouse_id" class="select"><option value="">{{ __('Yoxdur — soyuq satış / mərkəzi anbardan') }}</option>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($m?->warehouse_id === $w->id)>{{ $w->name }}</option>@endforeach</select></div>
<div><label class="label" for="rp-plan-{{ $id }}">{{ __('Aylıq satış planı') }}</label><input id="rp-plan-{{ $id }}" type="number" step="0.01" min="0" name="monthly_target" class="input" value="{{ $m?->monthly_target }}"></div>
<div class="flex items-end"><label class="flex h-9 items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4" @checked($m?->is_active ?? true)> {{ __('Aktiv') }}</label></div>
