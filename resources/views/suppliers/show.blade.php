@php use App\Support\Fmt; $s = $supplier; $cur = $s->currency; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('suppliers.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Təchizatçılar') }}</a>
        <span class="badge">{{ __('Kod') }} {{ $s->code }}</span>
        @if ($s->voen)<span class="badge">{{ __('VÖEN') }} {{ $s->voen }}</span>@endif
        @if ($s->country)<span class="badge">{{ $s->country }}</span>@endif
        <span class="badge">{{ __('Valyuta') }}: {{ $cur }}</span>
        @if ($s->phone)<span class="badge">{{ $s->phone }}</span>@endif
        @if ($s->contact_person)<span class="badge">{{ $s->contact_person }}</span>@endif
        @unless ($s->is_active)<span class="badge">{{ __('passiv') }}</span>@endunless
        <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('suppliers.edit', $s) }}" class="btn btn-sm"><x-icon name="edit" size="14"/> {{ __('Kartı düzəlt') }}</a>
            @can('finance.manage')<a href="{{ route('supplier-payments.create', ['supplier' => $s->id]) }}" class="btn btn-sm"><x-icon name="payments" size="14"/> {{ __('Ödəniş et') }}</a>@endcan
            <a href="{{ route('purchase-docs.create', ['type' => 'receipt', 'supplier' => $s->id]) }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Mədaxil qaiməsi') }}</a>
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Borcumuz')" :value="Fmt::number($balance['balance'] ?? 0, 2).' '.$cur" :tone="($balance['balance'] ?? 0) > 0.005 ? 'warn' : 'pos'" :big="true"/>
        <x-kpi :label="__('Gecikmiş')" :value="Fmt::number($balance['overdue'] ?? 0, 2).' '.$cur" :tone="($balance['overdue'] ?? 0) > 0.005 ? 'neg' : 'pos'"/>
        <x-kpi :label="__('Mədaxil')" :value="Fmt::number($balance['received'] ?? 0, 2).' '.$cur" :meta="__('qaytarma').': '.Fmt::number($balance['returned'] ?? 0, 2)"/>
        <x-kpi :label="__('Ödənilib')" :value="Fmt::number($balance['paid'] ?? 0, 2).' '.$cur" :meta="$s->payment_days ? __('ödəniş müddəti: :n gün', ['n' => $s->payment_days]) : null"/>
    </div>
    @if ($fx)
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ __('Məzənnə fərqi (AZN)') }}</h2>
                <div class="card-sub">{{ __('Mədaxillərin orta kursu ilə ödəniş günündəki kurs arasındakı fərq. Müsbət — AZN ilə çox ödənilib (zərər), mənfi — gəlir.') }}</div></div></div>
            <div class="grid grid-cols-2 gap-3 p-4 lg:grid-cols-4">
                <x-kpi :label="__('Mədaxillərin orta kursu')" :value="Fmt::number($fx['avg_rate'], 4)" :meta="'1 '.$cur.' = … AZN'"/>
                <x-kpi :label="__('Bugünkü kurs (Mərkəzi Bank)')" :value="$fx['current_rate'] !== null ? Fmt::number($fx['current_rate'], 4) : '—'"/>
                <x-kpi :label="__('Reallaşmış fərq')" :value="Fmt::money($fx['realized'])" :tone="$fx['realized'] > 0.005 ? 'neg' : ($fx['realized'] < -0.005 ? 'pos' : null)" :meta="__('ödənilib: :azn', ['azn' => Fmt::money($fx['paid_azn'])])"/>
                <x-kpi :label="__('Reallaşmamış fərq (açıq borc)')" :value="$fx['unrealized'] !== null ? Fmt::money($fx['unrealized']) : '—'" :tone="($fx['unrealized'] ?? 0) > 0.005 ? 'neg' : (($fx['unrealized'] ?? 0) < -0.005 ? 'pos' : null)"/>
            </div>
        </section>
    @endif
    @if ($s->address || $s->email || $s->note)
        <div class="card px-4 py-3 text-sm text-muted">{{ $s->address }}@if ($s->email) · {{ $s->email }}@endif @if ($s->note)<div class="mt-1 text-ink">{{ $s->note }}</div>@endif</div>
    @endif
    <x-table :table="$table" :rows="$rows"/>
    <x-audit-history type="Supplier" :id="$s->id"/>
</x-layouts.app>
