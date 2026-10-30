<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('returns.index') }}" class="btn btn-sm">{{ __('Qaytarma hesabatı') }}</a>
        <a href="{{ route('sales-returns.create') }}" class="btn btn-sm btn-primary sm:ml-auto"><x-icon name="plus" size="14"/> {{ __('Yeni qaytarma') }}</a>
    </div>
    <p class="text-xs text-muted">{{ __('Fakturadan qaytarma üçün fakturanı açıb «Qaytarma yarat» düyməsini basın — miqdar və qiymət fakturadan gəlir.') }}</p>
    <x-filters :filters="$filters" :show="['customer']" :own="['state']"><x-state-filter/></x-filters>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
