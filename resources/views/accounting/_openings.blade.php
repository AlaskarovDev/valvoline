@php use App\Support\Fmt; @endphp
<section class="card overflow-hidden">
    <div class="card-head"><div>
        <h2 class="card-title">{{ __('Dövrün əvvəlinə qalıqlar') }}</h2>
        <div class="card-sub">{{ __('İlin yanvar açılışı əvvəlki ilin son qalığına bərabər olmalıdır. Açılış qalığı pul vəsaitidir, gəlir deyil — P&L-ə daxil edilmir.') }}</div>
    </div></div>
    <ul class="divide-y divide-line text-sm">
        @forelse ($openings as $o)
            @php $ok = $o->previous_closing === null ? null : abs($o->opening - $o->previous_closing) < 0.01; @endphp
            <li class="px-4 py-3" x-data="{ open: {{ $o->year === $year ? 'true' : 'false' }} }">
                <button type="button" class="flex w-full items-center justify-between gap-3 text-left" @click="open = !open" :aria-expanded="open">
                    <span>
                        <span class="font-semibold">{{ __('01.01.:year', ['year' => $o->year]) }}</span>
                        @if ($ok === true)<span class="badge badge-pos ml-1">✓ {{ __('əvvəlki ilin sonu ilə eynidir') }}</span>
                        @elseif ($ok === false)<span class="badge badge-neg ml-1">{{ __('fərq: :sum', ['sum' => Fmt::money($o->opening - $o->previous_closing)]) }}</span>
                        @else<span class="badge ml-1">{{ __('əvvəlki il faylı yoxdur') }}</span>@endif
                    </span>
                    <span class="shrink-0 font-semibold tabular-nums">{{ Fmt::money($o->opening) }}</span>
                </button>
                <div x-show="open" x-cloak class="mt-2 space-y-1 text-xs text-muted">
                    @foreach ($o->accounts as $account => $amount)
                        <div class="flex justify-between gap-3"><span>{{ $account }}</span><span class="tabular-nums">{{ Fmt::money($amount) }}</span></div>
                    @endforeach
                    <div class="flex justify-between gap-3 border-t border-line pt-1">
                        <span>{{ __('Sistemdəki kassa qalığı (08 faylı, VALVO) — müqayisə üçün') }}</span><span class="tabular-nums">{{ Fmt::money($o->system_cash) }}</span>
                    </div>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-muted">{{ __('Məlumat yoxdur') }}</li>
        @endforelse
    </ul>
</section>
