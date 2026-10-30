@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('stock-docs.index', ['type' => $doc->type]) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge">{{ Fmt::date($doc->date) }}</span>
        <span @class(['badge', 'badge-primary' => $sign > 0, 'badge-neg' => $sign < 0])>{{ $types[$doc->type] }}</span>
        <span class="badge">{{ __('Anbar') }}: {{ $doc->warehouse->name }}</span>
        @if ($doc->workplace)<span class="badge">{{ __('İş yeri') }}: {{ $doc->workplace->name }}</span>@endif
        @if ($doc->purpose)<span class="badge">{{ __('Məqsəd') }}: {{ $doc->purpose }}</span>@endif
        <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('stock-docs.show', [$doc, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><x-icon name="print" size="14"/> {{ __('Çap et') }}</a>
            @if (auth()->user()->can('stock.manage') && ! $doc->cancelled_at)
                <x-cancel-button :action="route('stock-docs.destroy', $doc)" :confirm="__('Sənəd ləğv edilsin? Stok əvvəlki vəziyyətə qayıdacaq.')"/>
            @endif
        </div>
    </div>
    <x-cancelled-banner :doc="$doc"/>
    @if ($doc->note)<div class="card px-4 py-3 text-sm"><span class="label">{{ __('Qeyd') }}</span>{{ $doc->note }}</div>@endif
    <section class="card overflow-hidden"><div class="tbl-wrap">@include('stockdocs._lines')</div></section>
    <p class="text-xs text-muted">{{ __('Yaradıb') }}: {{ $doc->creator?->name ?? '—' }} · {{ $doc->created_at?->format('d.m.Y H:i') }}</p>
    <x-audit-history type="StockDocument" :id="$doc->id" :no="$doc->doc_no"/>
</x-layouts.app>
