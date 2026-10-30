@php use App\Support\Fmt; $isAdmin = auth()->user()->can('stock.manage'); @endphp
<x-layouts.app :title="$title">
    @if ($isAdmin)
        <section class="card" x-data="{ open: false }">
            <div class="card-head">
                <div><h2 class="card-title">{{ __('Anbarlar') }}</h2><div class="card-sub">{{ __('Səyyar maşın (ekspeditor) anbarı satış nümayəndəsinə və maşın nömrəsinə bağlanır. «Yolda (tranzit)» sistem anbarıdır.') }}</div></div>
                <button type="button" class="btn btn-sm btn-primary" @click="open = !open"><x-icon name="plus" size="14"/> {{ __('Yeni anbar') }}</button>
            </div>
            <form x-show="open" x-cloak method="POST" action="{{ route('warehouses.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                @csrf
                @include('warehouses._fields', ['w' => null])
                <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Yadda saxla') }}</button></div>
            </form>
        </section>
    @endif

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($warehouses as $w)
            @php $s = $stock[$w->id] ?? null; @endphp
            <section class="card" x-data="{ edit: false }">
                <div class="card-head">
                    <div class="min-w-0">
                        <h2 class="card-title truncate">{{ $w->name }}</h2>
                        <div class="card-sub">{{ $types[$w->type] ?? $w->type }} · {{ $w->code }}@if (! $w->is_active) · <span class="neg">{{ __('passiv') }}</span>@endif</div>
                    </div>
                    @if ($isAdmin && $w->type !== 'transit')<button type="button" class="btn btn-sm px-2" @click="edit = !edit" aria-label="{{ __('Düzəliş et') }}"><x-icon name="edit" size="14"/></button>@endif
                </div>
                <div class="grid grid-cols-3 gap-2 p-4 text-sm" x-show="!edit">
                    <div><div class="text-xs text-muted">{{ __('Real stok') }}</div><div class="font-semibold">{{ Fmt::qty($s->qty ?? 0) }}</div></div>
                    <div><div class="text-xs text-muted">{{ __('Dəyər') }}</div><div class="font-semibold">{{ Fmt::money($s->value ?? 0) }}</div></div>
                    <div><div class="text-xs text-muted">{{ __('Rezerv') }}</div><div class="font-semibold">{{ Fmt::qty($reserved[$w->id] ?? 0) }}</div></div>
                    <div class="col-span-3 text-xs text-muted">
                        {{ __(':n çeşid', ['n' => (int) ($s->skus ?? 0)]) }}@if (($s->negative ?? 0) > 0) · <span class="neg">{{ __(':n mal mənfi qalıqda', ['n' => (int) $s->negative]) }}</span>@endif
                        @if ($w->responsible) · {{ $w->responsible }}@endif @if ($w->vehicle_plate) · {{ $w->vehicle_plate }}@endif
                        @if ($w->type === 'transit' && $transitOpen) · <a href="{{ route('transfers.index', ['status' => 'open']) }}" class="text-primary-text hover:underline">{{ __(':n açıq transfer', ['n' => $transitOpen]) }}</a>@endif
                    </div>
                    <div class="col-span-3 flex flex-wrap gap-2">
                        <a href="{{ route('stock.index', ['warehouse' => $w->id]) }}" class="btn btn-sm">{{ __('Qalıq') }}</a>
                        @if ($w->type !== 'transit')<a href="{{ route('transfers.index', ['warehouse' => $w->id]) }}" class="btn btn-sm">{{ __('Transferlər') }}</a>@endif
                    </div>
                </div>
                @if ($isAdmin && $w->type !== 'transit')
                    <form x-show="edit" x-cloak method="POST" action="{{ route('warehouses.update', $w) }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
                        @csrf @method('PUT')
                        @include('warehouses._fields', ['w' => $w])
                        <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Yadda saxla') }}</button></div>
                    </form>
                @endif
            </section>
        @endforeach
    </div>
</x-layouts.app>
