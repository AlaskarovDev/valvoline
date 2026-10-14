@php use App\Support\Fmt; $o = $official; @endphp
<section class="card overflow-hidden">
    <div class="card-head">
        <div>
            <h2 class="card-title">{{ __('Rəsmi pul axını (mühasibat Cash Flow)') }}</h2>
            <div class="card-sub">{{ __('Bank daxilolmaları rəsmi gəlirdir, amma çoxu sistemdəki satışların pulu olduğu üçün P&L-ə təkrar yazılmır. Dövrün əvvəlinə qalıq keçmiş illərin pulu olduğu üçün gəlir deyil.') }}</div>
        </div>
        <a href="{{ route('accounting.reconcile') }}" class="btn btn-sm">{{ __('Tutuşdurma') }} →</a>
    </div>
    <ul class="divide-y divide-line text-sm">
        <li class="flex items-center justify-between gap-4 bg-surface-2 px-4 py-2.5 font-semibold">
            <span>{{ __('Dövrün əvvəlinə qalıq') }} @if ($o['opening_period'])<span class="text-xs font-normal text-muted">({{ Fmt::period($o['opening_period']) }})</span>@endif</span>
            <span class="tabular-nums">{{ $o['opening'] === null ? '—' : Fmt::money($o['opening']) }}</span>
        </li>
        <li class="flex items-center justify-between gap-4 px-4 py-2.5">
            <span class="font-medium">{{ __('+ Müştərilərdən rəsmi daxilolmalar') }}</span>
            <span class="tabular-nums font-medium pos">{{ Fmt::money($o['customer']) }}</span>
        </li>
        @foreach ([['included', __('sistemdə satış kimi var — P&L-də satışdadır, təkrar sayılmır'), ''], ['extra', __('sistemdə olmayan satış — P&L-ə əlavə olunub'), 'pos'], ['pending', __('yoxlanılmalı — P&L-ə düşmür'), 'warn'], ['not_revenue', __('gəlir deyil'), '']] as [$key, $label, $tone])
            @continue(abs($o[$key]) < 0.005)
            <li class="flex items-center justify-between gap-4 px-4 py-2 pl-9 text-[13px] text-muted">
                <span>{{ $label }}</span><span class="tabular-nums {{ $tone }}">{{ Fmt::money($o[$key]) }}</span>
            </li>
        @endforeach
        @foreach ([['export', __('+ Re-eksport alıcısından (P&L-də re-eksport blokunda)')], ['founder', __('+ Təsisçidən (borc, gəlir deyil)')], ['other_income', __('+ Digər daxilolmalar')]] as [$key, $label])
            @continue(abs($o[$key]) < 0.005)
            <li class="flex items-center justify-between gap-4 px-4 py-2.5"><span>{{ $label }}</span><span class="tabular-nums">{{ Fmt::money($o[$key]) }}</span></li>
        @endforeach
        <li class="flex items-center justify-between gap-4 px-4 py-2.5"><span>{{ __('− Ödənişlər (bütün növlər)') }}</span><span class="tabular-nums neg">{{ Fmt::money(-$o['payments']) }}</span></li>
        @if (abs($o['fx']) > 0.005)
            <li class="flex items-center justify-between gap-4 px-4 py-2.5"><span>{{ __('± Valyutanın yenidən qiymətləndirilməsi') }}</span><span class="tabular-nums">{{ Fmt::money($o['fx']) }}</span></li>
        @endif
        <li class="flex items-center justify-between gap-4 bg-surface-2 px-4 py-2.5 font-semibold">
            <span>{{ __('Dövrün sonuna qalıq') }} @if ($o['closing_period'])<span class="text-xs font-normal text-muted">({{ Fmt::period($o['closing_period']) }})</span>@endif</span>
            <span class="tabular-nums">{{ $o['closing'] === null ? '—' : Fmt::money($o['closing']) }}</span>
        </li>
    </ul>
</section>
