<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <nav class="flex flex-wrap gap-2" aria-label="{{ __('Bölmələr') }}">
            @foreach (['months' => __('Aylar üzrə'), 'customers' => __('Müştərilər üzrə ƏDV borcu'), 'suppliers' => __('Təchizatçılar üzrə')] as $k => $l)
                <a href="{{ route('vat.index', ['tab' => $k, 'year' => $year]) }}" @class(['btn btn-sm', 'btn-primary' => $tab === $k])>{{ $l }}</a>
            @endforeach
        </nav>
        @if ($tab === 'months')
            <form method="GET" class="flex items-center gap-2 sm:ml-auto">
                <label class="label mb-0" for="vat-year">{{ __('İl') }}</label>
                <select id="vat-year" name="year" class="select w-28" onchange="this.form.submit()">@foreach ($years as $y)<option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>@endforeach</select>
            </form>
        @endif
    </div>

    <div class="card px-4 py-3 text-sm text-muted">
        @if ($tab === 'months')
            {{ __('Satışın ƏDV-si satış sətirlərindən (fakturalar və idxal olunmuş satışlar), qaytarmanın ƏDV-si qaytarmalardan hesablanır. Əvəzləşdirilən ƏDV daxil olan e-qaimələrdəndir; mədaxil qaimələrinin ƏDV-si yalnız müqayisə üçündür. «Büdcəyə» = satışın ƏDV-si − qaytarma − e-qaimə ƏDV-si (bəyannamədən əvvəl təxmini).') }}
        @elseif ($tab === 'customers')
            {{ __('Müştərinin fakturalanmış ƏDV-si ilə ƏDV depozit hesabına onun VÖEN-i ilə daxil olan ödənişlər tutuşdurulur. Müştəri əsas məbləği ödəyib ƏDV-ni depozitə köçürməyibsə, burada borc kimi görünür.') }}
        @else
            {{ __('Təchizatçıların e-qaimələrindəki ƏDV ilə depozit hesabından onların VÖEN-i ilə köçürülən məbləğ tutuşdurulur.') }}
        @endif
        @if ($accounts->isEmpty())
            <div class="mt-2 warn">{{ __('«Depozit» növlü bank hesabı yoxdur — Bank hesablarında ƏDV depozit hesabının növünü «Depozit» seçin.') }}</div>
        @elseif ($tab !== 'months')
            <div class="mt-2">{{ __('Depozit hesabı: :names. Tutuşdurma üçün hesabın çıxarışını Bank hesablarında yükləyin — qarşı tərəfin VÖEN-i çıxarışda olmalıdır.', ['names' => $accounts->pluck('name')->implode(', ')]) }}
                <a href="{{ route('bank.index') }}" class="text-primary-text hover:underline">{{ __('Bank hesabları') }}</a></div>
        @endif
        @if ($tab === 'suppliers' && ! $hasEinvoices)
            <div class="mt-2 warn">{{ __('Daxil olan e-qaimə hələ yüklənməyib.') }} <a href="{{ route('einvoices.incoming') }}" class="text-primary-text hover:underline">{{ __('Alış e-qaimələri') }}</a></div>
        @endif
    </div>

    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
