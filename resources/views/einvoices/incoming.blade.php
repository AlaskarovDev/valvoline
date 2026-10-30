<x-layouts.app :title="$title">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Bölmələr') }}">
        @foreach (['new' => __('Mədaxil yaradılmayıb'), 'linked' => __('Mədaxil yaradılıb'), 'all' => __('Hamısı')] as $k => $l)
            <a href="{{ request()->url().'?'.http_build_query($filters->query(['tab' => $k])) }}" @class(['btn btn-sm', 'btn-primary' => $tab === $k])>{{ $l }}</a>
        @endforeach
    </nav>

    <section class="card">
        <div class="card-head"><div>
            <h2 class="card-title">{{ __('Daxil olan e-qaimələri yüklə') }}</h2>
            <div class="card-sub">{{ __('e-taxes-də alış e-qaimələrini Excel-ə çıxarın (sayt «Excel» və ya InvoiceBot) və faylı burada yükləyin. Təkrar yükləmə statusları yeniləyir, ikinci nüsxə yaratmır.') }}</div>
        </div></div>
        <form method="POST" action="{{ route('einvoices.import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3 p-4">
            @csrf
            <div class="min-w-0 flex-1"><label class="label" for="eqa-file">{{ __('Fayl (EQF-ALIŞ, .xlsx)') }}</label><input id="eqa-file" type="file" name="file" required accept=".xlsx" class="input py-1.5"></div>
            <button class="btn btn-primary"><x-icon name="import" size="14"/> {{ __('Yüklə') }}</button>
        </form>
    </section>

    <x-filters :filters="$filters" :show="[]" :own="['tab']"/>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
