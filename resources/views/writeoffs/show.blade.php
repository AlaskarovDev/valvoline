@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('write-offs.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ Fmt::date($doc->date) }}</span>
        <span class="badge">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</span>
        <span class="badge badge-neg">{{ $reasons[$doc->reason] ?? $doc->reason }}</span>
        @if ($transfer)<a href="{{ route('transfers.show', $transfer->id) }}" class="badge badge-primary">{{ __('Anbar transferi') }} {{ $transfer->doc_no }}</a>@endif
        <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('write-offs.show', [$doc, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><x-icon name="print" size="14"/> {{ __('Çap et') }}</a>
            @if (auth()->user()->isAdmin())
                <form method="POST" action="{{ route('write-offs.destroy', $doc) }}" onsubmit="return confirm(@js(__('Silinmə aktı ləğv edilsin? Mal stoka qaytarılacaq.')))">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">{{ __('Ləğv et') }}</button></form>
            @endif
        </div>
    </div>
    @if ($doc->note)<div class="card px-4 py-3 text-sm"><span class="label">{{ __('Qeyd') }}</span>{{ $doc->note }}</div>@endif
    @include('writeoffs._lines')
    <p class="text-xs text-muted">{{ __('Yaradıb') }}: {{ $doc->creator?->name ?? '—' }} · {{ $doc->created_at?->format('d.m.Y H:i') }}</p>
</x-layouts.app>
