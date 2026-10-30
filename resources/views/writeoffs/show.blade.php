@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('write-offs.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ Fmt::date($doc->date) }}</span>
        <span class="badge">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</span>
        @if ($doc->workplace)<span class="badge">{{ __('İş yeri') }}: {{ $doc->workplace->name }}</span>@endif
        <span class="badge badge-neg">{{ $reasons[$doc->reason] ?? $doc->reason }}</span>
        @if ($transfer)<a href="{{ route('transfers.show', $transfer->id) }}" class="badge badge-primary">{{ __('Anbar transferi') }} {{ $transfer->doc_no }}</a>@endif
        <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('write-offs.show', [$doc, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><x-icon name="print" size="14"/> {{ __('Çap et') }}</a>
            @if (auth()->user()->can('stock.manage') && ! $doc->cancelled_at)
                <x-cancel-button :action="route('write-offs.destroy', $doc)" :confirm="__('Silinmə aktı ləğv edilsin? Mal stoka qaytarılacaq.')"/>
            @endif
        </div>
    </div>
    <x-cancelled-banner :doc="$doc"/>
    @if ($doc->note)<div class="card px-4 py-3 text-sm"><span class="label">{{ __('Qeyd') }}</span>{{ $doc->note }}</div>@endif
    @include('writeoffs._lines')
    <p class="text-xs text-muted">{{ __('Yaradıb') }}: {{ $doc->creator?->name ?? '—' }} · {{ $doc->created_at?->format('d.m.Y H:i') }}</p>
    <x-audit-history type="StockWriteOff" :id="$doc->id" :no="$doc->doc_no"/>
</x-layouts.app>
