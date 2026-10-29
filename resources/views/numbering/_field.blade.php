{{-- Nömrələmə servisi ilə işləyən nömrə / kod sahəsi: auto — sistem verir, auto_editable — təklif olunur, manual — yazılır --}}
@php
    $mode = $numbering['mode'];
    $preview = $numbering['preview'];
    $editing = filled($current ?? null);
    $value = old($name, $editing ? $current : ($mode === 'auto_editable' ? $preview : ''));
@endphp
<div>
    <label class="label" for="{{ $id }}">{{ $label }}</label>
    @if ($mode === 'auto' && ! $editing)
        <input id="{{ $id }}" class="input font-mono text-muted" value="{{ __('avtomatik') }}: {{ $preview }}" readonly aria-readonly="true">
    @else
        <input id="{{ $id }}" name="{{ $name }}" maxlength="{{ $max ?? 64 }}" class="input font-mono" value="{{ $value }}" @required($mode === 'manual' || $editing) @readonly($mode === 'auto' && $editing)
               @if ($mode === 'manual' && ! $editing) placeholder="{{ __('məs.') }} {{ $preview }}" @endif>
        @if ($mode === 'auto_editable' && ! $editing)<input type="hidden" name="{{ $name }}_offered" value="{{ $preview }}">@endif
    @endif
    @if (auth()->user()?->isAdmin())<a href="{{ route('numbering.index') }}#{{ $key }}" class="mt-1 inline-block text-[11px] text-muted hover:underline">{{ __('Nömrələmə qaydası') }}</a>@endif
</div>
