@if ($paginator->hasPages())
    <nav class="flex flex-wrap items-center justify-between gap-2" aria-label="{{ __('Səhifələr') }}">
        <div class="text-xs text-muted">
            {{ number_format($paginator->firstItem(), 0, ',', ' ') }}–{{ number_format($paginator->lastItem(), 0, ',', ' ') }} / {{ number_format($paginator->total(), 0, ',', ' ') }}
        </div>
        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn btn-sm opacity-40" aria-disabled="true">‹</span>
            @else
                <a class="btn btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Əvvəlki') }}">‹</a>
            @endif
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-1 text-muted">…</span>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="btn btn-sm btn-primary" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="btn btn-sm" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())
                <a class="btn btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Növbəti') }}">›</a>
            @else
                <span class="btn btn-sm opacity-40" aria-disabled="true">›</span>
            @endif
        </div>
    </nav>
@endif
