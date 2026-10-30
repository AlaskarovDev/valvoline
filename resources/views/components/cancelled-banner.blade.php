@props(['doc'])
@if ($doc->cancelled_at)
    <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--negative)" role="status">
        <div class="font-semibold neg">{{ __('Bu sənəd ləğv edilib') }}</div>
        <div class="mt-0.5 text-muted">
            {{ $doc->cancelled_at->format('d.m.Y H:i') }} · {{ $doc->canceller?->name ?? '—' }}
            @if ($doc->cancel_reason) · {{ __('Səbəb') }}: <span class="text-ink">{{ $doc->cancel_reason }}</span>@endif
        </div>
        <div class="mt-1 text-xs text-muted">{{ __('Sənədin stoka, borca və hesabatlara təsiri götürülüb; sətirlər tarixçə üçün göstərilir.') }}</div>
    </div>
@endif
