@php $current = app()->getLocale(); @endphp
<nav {{ $attributes->merge(['class' => 'flex items-center gap-0.5 rounded-lg border border-line bg-surface p-0.5']) }} aria-label="{{ __('Dil') }}">
    @foreach (\App\Support\Locale::SUPPORTED as $code => $name)
        <a href="{{ route('locale', $code) }}" lang="{{ $code }}" title="{{ $name }}"
           @class(['rounded-md px-2 py-1 text-[11px] font-semibold uppercase leading-none', 'bg-primary text-white' => $current === $code, 'text-muted hover:text-ink' => $current !== $code])
           @if($current === $code) aria-current="true" @endif>{{ $code }}</a>
    @endforeach
</nav>
