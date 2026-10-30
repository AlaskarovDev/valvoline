<x-layouts.app :title="$title">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Bölmələr') }}">
        @foreach (['pending' => [__('Göndəriləcək'), $counts->pending], 'exported' => [__('İxrac edilib, seriya gözləyir'), $counts->exported], 'linked' => [__('e-qaimə ilə əlaqəli'), $counts->linked], 'returns' => [__('Qaytarmalar'), null]] as $k => [$l, $n])
            <a href="{{ request()->url().'?'.http_build_query($filters->query(['tab' => $k])) }}" @class(['btn btn-sm', 'btn-primary' => $tab === $k])>{{ $l }}@if ($n !== null) <span class="text-xs opacity-75">{{ (int) $n }}</span>@endif</a>
        @endforeach
    </nav>

    <section class="card" x-data="{ help: false }">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ __('e-taxes ilə mübadilə') }}</h2>
                <div class="card-sub">{{ __('Fakturalar InvoiceBot / e-taxes «Təqdim etmə» Excel nümunəsinə çıxarılır, e-qaimə seriya nömrələri e-taxes-in öz Excel çıxışından geri yazılır.') }}</div>
            </div>
            <button type="button" class="btn btn-sm" @click="help = !help" :aria-expanded="help">{{ __('Necə işləyir?') }}</button>
        </div>
        <ol class="list-decimal space-y-1 border-b border-line px-9 py-3 text-sm" x-show="help" x-cloak>
            <li>{{ __('«Göndəriləcək» bölməsində problemi olmayan fakturaları Excel-ə ixrac edin. Hər fakturanın «Əsas qeyd»-inə faktura № yazılır.') }}</li>
            <li>{{ __('InvoiceBot-da «Təqdim etmə faylı seçin» ilə faylı yükləyin — e-taxes-də qaralamalar yaranır; onları yoxlayıb imzalayın.') }}</li>
            <li>{{ __('e-taxes-də göndərilmiş e-qaimələri Excel-ə çıxarın (InvoiceBot «Excel» düyməsi) və faylı aşağıda «Seriya nömrələrini tutuşdur» ilə yükləyin.') }}</li>
            <li>{{ __('Seriya nömrəsi fakturaya yazılır; qaytarma qaiməsi də həmin seriya ilə «Qaytarmalar» bölməsindən ixrac olunur.') }}</li>
        </ol>
        <div class="grid gap-4 p-4 md:grid-cols-2">
            @if ($tab === 'returns')
                <form method="POST" action="{{ route('einvoices.export-returns') }}" class="flex flex-col gap-2">
                    @csrf
                    <input type="hidden" name="from" value="{{ $filters->fromDate() }}"><input type="hidden" name="to" value="{{ $filters->toDate() }}">
                    <div class="text-sm text-muted">{{ __('Dövrün ixrac edilməmiş qaytarma qaimələri «Qaytarma» nümunəsinə çıxarılır (əsas fakturanın e-qaimə seriyası olmalıdır).') }}</div>
                    <div><button class="btn btn-primary"><x-icon name="download" size="14"/> {{ __('Qaytarmaları Excel-ə ixrac et') }}</button></div>
                </form>
            @else
                <form method="POST" action="{{ route('einvoices.export') }}" class="flex flex-col gap-2">
                    @csrf
                    <input type="hidden" name="from" value="{{ $filters->fromDate() }}"><input type="hidden" name="to" value="{{ $filters->toDate() }}">
                    <div class="text-sm text-muted">{{ __('Dövrün göndərilməmiş fakturaları. Problemi olan faktura ixraca düşmür — əvvəlcə müştəri VÖEN-ini və məhsulun «Mal kodu»nu doldurun.') }}</div>
                    <div><button class="btn btn-primary" @disabled($ready !== null && $ready <= 0)><x-icon name="download" size="14"/> {{ $ready !== null ? __('Hazır :n fakturanı Excel-ə ixrac et', ['n' => max(0, $ready)]) : __('Göndəriləcək fakturaları Excel-ə ixrac et') }}</button></div>
                </form>
            @endif
            <form method="POST" action="{{ route('einvoices.reconcile') }}" enctype="multipart/form-data" class="flex flex-col gap-2">
                @csrf
                <label class="label" for="eq-file">{{ __('Seriya nömrələrini tutuşdur: e-taxes satış e-qaimələri Excel-i (EQF)') }}</label>
                <div class="flex flex-wrap gap-2">
                    <input id="eq-file" type="file" name="file" required accept=".xlsx" class="input min-w-0 flex-1 py-1.5">
                    <button class="btn"><x-icon name="import" size="14"/> {{ __('Yüklə') }}</button>
                </div>
            </form>
        </div>
    </section>

    <x-filters :filters="$filters" :show="[]" :own="['tab']"/>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
