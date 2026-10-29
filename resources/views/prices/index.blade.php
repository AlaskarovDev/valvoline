<x-layouts.app :title="$title">
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2" x-data="{ bulk: false, upload: false }">
        <section class="card lg:col-span-2">
            <form method="GET" class="flex flex-wrap items-end gap-2 px-4 py-3">
                <div><label class="label" for="pf-state">{{ __('Vəziyyət') }}</label>
                    <select id="pf-state" name="state" class="select h-[34px] text-xs" onchange="this.form.submit()">
                        @foreach (['current' => __('Qüvvədə olan'), 'future' => __('Gələcək'), 'expired' => __('Bitmiş / dayandırılmış'), 'all' => __('Hamısı')] as $k => $l)<option value="{{ $k }}" @selected($state === $k)>{{ $l }}</option>@endforeach
                    </select></div>
                <div><label class="label" for="pf-type">{{ __('Növ') }}</label>
                    <select id="pf-type" name="type" class="select h-[34px] text-xs" onchange="this.form.submit()">
                        <option value="">{{ __('Hamısı') }}</option><option value="sale" @selected(request('type') === 'sale')>{{ __('Satış') }}</option><option value="purchase" @selected(request('type') === 'purchase')>{{ __('Alış') }}</option>
                    </select></div>
                <div><label class="label" for="pf-list">{{ __('Qiymət növü') }}</label>
                    <select id="pf-list" name="list" class="select h-[34px] text-xs" onchange="this.form.submit()">
                        <option value="">{{ __('Hamısı') }}</option>
                        @foreach ($priceLists as $l)<option value="{{ $l->id }}" @selected((string) request('list') === (string) $l->id)>{{ $l->name }}</option>@endforeach
                    </select></div>
                <div><label class="label" for="pf-kind">{{ __('Müştəriyə özəl') }}</label>
                    <select id="pf-kind" name="kind" class="select h-[34px] text-xs" onchange="this.form.submit()">
                        <option value="">{{ __('Hamısı') }}</option><option value="customer" @selected(request('kind') === 'customer')>{{ __('Yalnız müştəriyə özəl') }}</option>
                    </select></div>
                <div><label class="label" for="pf-customer">{{ __('Müştəri') }}</label>
                    <select id="pf-customer" name="customer" class="select h-[34px] max-w-56 text-xs" onchange="this.form.submit()">
                        <option value="">{{ __('Hamısı') }}</option>
                        @foreach ($customers as $c)<option value="{{ $c->id }}" @selected((string) request('customer') === (string) $c->id)>{{ $c->name }}</option>@endforeach
                    </select></div>
                <div class="flex gap-2 sm:ml-auto">
                    <button type="button" class="btn btn-sm h-[34px]" @click="upload = !upload; bulk = false"><x-icon name="import" size="14"/> {{ __('Excel-dən yüklə') }}</button>
                    <button type="button" class="btn btn-sm btn-primary h-[34px]" @click="bulk = !bulk; upload = false"><x-icon name="refresh" size="14"/> {{ __('Toplu qiymət dəyişikliyi') }}</button>
                </div>
            </form>
        </section>

        <section class="card lg:col-span-2" x-show="bulk" x-cloak>
            <div class="card-head"><div><h2 class="card-title">{{ __('Toplu qiymət dəyişikliyi') }}</h2><div class="card-sub">{{ __('Seçilmiş mallar üçün yeni tarixli kartlar yaradılır, köhnələri bağlanır — tarixçə saxlanır.') }}</div></div></div>
            <form method="POST" action="{{ route('prices.bulk') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6" onsubmit="return confirm(@js(__('Seçilmiş mallar üçün yeni qiymətlər yaradılsın?')))">
                @csrf
                <div><label class="label" for="b-list">{{ __('Qiymət növü') }}</label><select id="b-list" name="price_list_id" class="select">@foreach ($priceLists as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select></div>
                <div><label class="label" for="b-method">{{ __('Üsul') }}</label>
                    <select id="b-method" name="method" class="select">
                        <option value="percent">{{ __('Cari satış qiymətinə ± %') }}</option>
                        <option value="markup">{{ __('Alış qiymətinə əlavə %') }}</option>
                        <option value="fixed">{{ __('Sabit qiymət') }}</option>
                    </select></div>
                <div><label class="label" for="b-value">{{ __('Dəyər') }}</label><input id="b-value" type="number" step="0.01" name="value" required class="input" placeholder="5"></div>
                <div><label class="label" for="b-round">{{ __('Yuvarlaqlaşdırma') }}</label>
                    <select id="b-round" name="round" class="select"><option value="0.01">0,01</option><option value="0.05">0,05</option><option value="0.1">0,10</option><option value="1">1,00</option></select></div>
                <div><label class="label" for="b-from">{{ __('Başlama tarixi') }}</label><input id="b-from" type="date" name="valid_from" required class="input" value="{{ now()->toDateString() }}"></div>
                <div><label class="label" for="b-to">{{ __('Bitmə tarixi') }}</label><input id="b-to" type="date" name="valid_to" class="input"></div>
                <div><label class="label" for="b-brand">{{ __('Marka') }}</label><select id="b-brand" name="brand" class="select"><option value="">{{ __('Hamısı') }}</option>@foreach ($brands as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                <div><label class="label" for="b-class">{{ __('Sinif') }}</label><select id="b-class" name="class" class="select"><option value="">{{ __('Hamısı') }}</option>@foreach ($classes as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                <div><label class="label" for="b-cat">{{ __('Kateqoriya') }}</label><select id="b-cat" name="category" class="select"><option value="">{{ __('Hamısı') }}</option>@foreach ($categories as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                <div class="sm:col-span-2"><label class="label" for="b-q">{{ __('Kod və ya ad içində') }}</label><input id="b-q" name="q" maxlength="100" class="input" placeholder="MAXLIFE"></div>
                <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Tətbiq et') }}</button></div>
            </form>
        </section>

        <section class="card lg:col-span-2" x-show="upload" x-cloak>
            <div class="card-head"><div><h2 class="card-title">{{ __('Excel-dən qiymət yükləmə') }}</h2><div class="card-sub">{{ __('Sütunlar: A — Mal kodu, B — Qiymət, C — Başlama tarixi (istəyə görə). Birinci sətir başlıqdır.') }}</div></div></div>
            <form method="POST" action="{{ route('prices.import') }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-4">
                @csrf
                <div class="sm:col-span-2"><label class="label" for="i-file">{{ __('Fayl') }}</label><input id="i-file" type="file" name="file" required accept=".xlsx,.xls,.csv" class="input py-1.5"></div>
                <div><label class="label" for="i-list">{{ __('Qiymət növü') }}</label><select id="i-list" name="price_list_id" class="select">@foreach ($priceLists as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select></div>
                <div><label class="label" for="i-from">{{ __('Başlama tarixi') }}</label><input id="i-from" type="date" name="valid_from" required class="input" value="{{ now()->toDateString() }}"></div>
                <div class="sm:col-span-4 flex justify-end"><button class="btn btn-primary"><x-icon name="import" size="14"/> {{ __('Yüklə') }}</button></div>
            </form>
        </section>
    </div>

    <x-table :table="$table" :rows="$rows">
        <x-slot:actions>
            <a href="{{ route('prices.list') }}" class="btn btn-sm"><x-icon name="invoice" size="14"/> {{ __('Qiymət siyahısı') }}</a>
        </x-slot:actions>
    </x-table>
</x-layouts.app>
