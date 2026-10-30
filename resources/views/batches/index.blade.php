@php use App\Support\Fmt; $isAdmin = auth()->user()->can('stock.manage'); @endphp
<x-layouts.app :title="$title">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Vaxtı bitmiş')" :value="Fmt::qty($kpi['expired_qty'])" :tone="$kpi['expired_qty'] > 0 ? 'neg' : null"
               :meta="Fmt::money($kpi['expired_value']).' · '.__(':n mal', ['n' => $kpi['expired_products']])" :href="route('batches.index', ['tab' => 'expired'])"/>
        <x-kpi :label="__('Tezliklə bitəcək')" :value="Fmt::qty($kpi['soon_qty'])" :tone="$kpi['soon_qty'] > 0 ? 'warn' : null" :meta="Fmt::money($kpi['soon_value'])" :href="route('batches.index', ['tab' => 'soon'])"/>
        <x-kpi :label="__('Qeydiyyatlı partiya')" :value="Fmt::number($kpi['registered'])" :href="route('batches.index', ['tab' => 'all', 'source' => 'registered'])"/>
        <x-kpi :label="__('Tarixsiz partiya')" :value="Fmt::number($kpi['no_date'])" :href="route('batches.index', ['tab' => 'no_date'])"/>
    </div>

    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Bölmələr') }}">
        @foreach (['expired' => __('Vaxtı bitmiş'), 'soon' => __('Tezliklə bitəcək'), 'all' => __('Bütün qalıqlı partiyalar'), 'no_date' => __('Tarixsiz')] as $k => $l)
            <a href="{{ route('batches.index', ['tab' => $k]) }}" @class(['btn btn-sm', 'btn-primary' => $tab === $k])>{{ $l }}</a>
        @endforeach
    </nav>

    @if ($isAdmin)
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2" x-data="{ add: false, upload: false }">
            <div class="flex flex-wrap gap-2 xl:col-span-2">
                <button type="button" class="btn btn-sm" @click="add = !add; upload = false"><x-icon name="plus" size="14"/> {{ __('Partiya qeyd et') }}</button>
                <button type="button" class="btn btn-sm" @click="upload = !upload; add = false"><x-icon name="import" size="14"/> {{ __('Excel-dən yüklə') }}</button>
            </div>
            <section class="card xl:col-span-2" x-show="add" x-cloak>
                <div class="card-head"><div><h2 class="card-title">{{ __('Partiya qeyd et') }}</h2><div class="card-sub">{{ __('Son istifadə tarixi boşdursa, istehsal tarixi + malın saxlanma müddəti götürülür.') }}</div></div></div>
                <form method="POST" action="{{ route('batches.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
                    @csrf
                    <div class="sm:col-span-2"><label class="label" for="b-product">{{ __('Mal') }}</label>
                        <select id="b-product" name="product_id" class="select" required>@foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}@if ($p->shelf_life_months) ({{ __(':n ay', ['n' => $p->shelf_life_months]) }})@endif</option>@endforeach</select></div>
                    <div><label class="label" for="b-no">{{ __('Partiya / lot №') }}</label><input id="b-no" name="batch_no" required maxlength="64" class="input font-mono"></div>
                    <div><label class="label" for="b-prod">{{ __('İstehsal tarixi') }}</label><input id="b-prod" type="date" name="production_date" class="input"></div>
                    <div><label class="label" for="b-exp">{{ __('Son istifadə tarixi') }}</label><input id="b-exp" type="date" name="expiry_date" class="input"></div>
                    <div><label class="label" for="b-qty">{{ __('Miqdar') }}</label><input id="b-qty" type="number" step="any" min="0.0001" name="qty" required class="input"></div>
                    <div><label class="label" for="b-wh">{{ __('Anbar') }}</label><select id="b-wh" name="warehouse_id" class="select"><option value="">—</option>@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
                    <div><label class="label" for="b-pb">{{ __('İdxal partiyası') }}</label><select id="b-pb" name="purchase_batch_id" class="select"><option value="">—</option>@foreach ($purchaseBatches as $b)<option value="{{ $b->id }}">#{{ $b->batch_no }} {{ $b->name }} · {{ Fmt::date($b->date) }}</option>@endforeach</select></div>
                    <div><label class="label" for="b-rec">{{ __('Daxil olma tarixi') }}</label><input id="b-rec" type="date" name="received_date" class="input" value="{{ now()->toDateString() }}"></div>
                    <div><label class="label" for="b-sup">{{ __('Təchizatçı') }}</label><input id="b-sup" name="supplier" maxlength="255" class="input"></div>
                    <div><label class="label" for="b-note">{{ __('Qeyd') }}</label><input id="b-note" name="note" maxlength="255" class="input"></div>
                    <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Yadda saxla') }}</button></div>
                </form>
            </section>
            <section class="card xl:col-span-2" x-show="upload" x-cloak>
                <div class="card-head"><div><h2 class="card-title">{{ __('Excel-dən partiya yükləmə') }}</h2><div class="card-sub">{{ __('Sütunlar: A — Mal kodu, B — Partiya №, C — İstehsal tarixi, D — Son istifadə tarixi, E — Miqdar. Birinci sətir başlıqdır.') }}</div></div></div>
                <form method="POST" action="{{ route('batches.import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3 p-4">
                    @csrf
                    <div class="min-w-0 flex-1"><label class="label" for="b-file">{{ __('Fayl') }}</label><input id="b-file" type="file" name="file" required accept=".xlsx,.xls,.csv" class="input py-1.5"></div>
                    <button class="btn btn-primary"><x-icon name="import" size="14"/> {{ __('Yüklə') }}</button>
                </form>
            </section>
        </div>
    @endif

    @if ($tab === 'expired' && $isAdmin && $expiredRows->isNotEmpty())
        <section class="card" x-data="{ all: true }">
            <div class="card-head"><div><h2 class="card-title">{{ __('Vaxtı bitmiş malın silinməsi') }}</h2><div class="card-sub">{{ __('Seçilmiş partiyaların qalığı üçün silinmə aktı yaradılır: real stok azalır, dəyəri itki kimi uçota düşür.') }}</div></div></div>
            <form method="POST" action="{{ route('batches.write-off') }}" class="flex flex-col gap-3 p-4" onsubmit="return confirm(@js(__('Seçilmiş vaxtı bitmiş mallar silinsin?')))">
                @csrf
                <div class="grid max-h-60 grid-cols-1 gap-1 overflow-auto text-sm md:grid-cols-2">
                    @foreach ($expiredRows as $r)
                        <label class="flex items-center gap-2"><input type="checkbox" name="items[]" value="{{ $r->product_id }}:{{ $r->batch_id }}:{{ $r->remaining }}" class="size-4" checked>
                            <span class="font-mono text-xs text-muted">{{ $r->code }}</span> {{ $r->name }} · {{ $r->batch_no }} · <span class="neg">{{ Fmt::date($r->expiry_date) }}</span> · <strong>{{ Fmt::qty($r->remaining) }}</strong></label>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <div><label class="label" for="wo-wh">{{ __('Anbar') }}</label><select id="wo-wh" name="warehouse_id" class="select h-[34px]">@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
                    <div class="min-w-0 flex-1"><label class="label" for="wo-note">{{ __('Qeyd') }}</label><input id="wo-note" name="note" maxlength="500" class="input h-[34px]"></div>
                    <button class="btn btn-danger h-[34px]"><x-icon name="trash" size="14"/> {{ __('Silinmə aktı yarat') }}</button>
                </div>
            </form>
        </section>
    @endif

    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
