<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('customers.create') }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Yeni müştəri') }}</a>
    </div>
    <x-filters :filters="$filters" :show="['manager']" :dates="false" :own="['tab', 'days', 'type', 'only']">
        <select name="only" class="select h-[30px] w-auto text-xs" aria-label="{{ __('Kredit vəziyyəti') }}" onchange="this.form.submit()">
            <option value="">{{ __('Bütün kredit vəziyyətləri') }}</option>
            <option value="overdue" @selected(request('only') === 'overdue')>{{ __('Gecikmiş borcu olanlar') }}</option>
            <option value="blocked" @selected(request('only') === 'blocked')>{{ __('Bloklu müştərilər') }}</option>
        </select>
        <input type="hidden" name="tab" value="{{ $tab }}">
        <select name="type" class="select h-[30px] w-auto text-xs" aria-label="{{ __('Müştəri tipi') }}" onchange="this.form.submit()">
            <option value="" @selected(request('type') === null || request('type') === '')>{{ __('Yalnız müştərilər') }}</option>
            <option value="internal" @selected(request('type') === 'internal')>{{ __('Daxili (müştəri deyil)') }}</option>
            <option value="any" @selected(request('type') === 'any')>{{ __('Hamısı') }}</option>
        </select>
        @if ($tab === 'inactive')
            <label class="flex items-center gap-1.5 text-xs text-muted">
                {{ __('Son alışdan') }}
                <input type="number" min="1" name="days" value="{{ $days }}" class="input h-[30px] w-20 text-xs">
                {{ __('gün keçib') }}
            </label>
        @endif
    </x-filters>

    <nav class="tabs" aria-label="{{ __('Görünüş') }}">
        <a href="{{ route('customers.index', request()->except(['tab', 'customers_page'])) }}" @class(['tab', 'active' => $tab === 'all'])>{{ __('Bütün müştərilər') }} ({{ number_format($counts['all'], 0, ',', ' ') }})</a>
        <a href="{{ route('customers.index', array_merge(request()->except('customers_page'), ['tab' => 'inactive'])) }}" @class(['tab', 'active' => $tab === 'inactive'])>{{ __('Uzun müddət alış etməyənlər') }}</a>
    </nav>

    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
