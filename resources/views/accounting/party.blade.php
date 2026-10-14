@php
    use App\Support\Fmt;
    $sectionLabels = ['income' => __('Mədaxil'), 'payment' => __('Ödəniş')];
    // hər il faylı həmin ilin sonuna açıq sənədlərdir — qalıq seçilmiş il üzrə (sənəd seçilibsə onun məbləği)
    $docTotal = (float) ($document ? $focus : $documents->where('year', $year))->sum('amount');
@endphp
<div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
    <div class="min-w-0">
        <h2 class="text-base font-semibold" data-modal-title>{{ $party }}</h2>
        <div class="text-xs text-muted">{{ __('Hesab :code', ['code' => $code]) }}@if ($document) · {{ $document }}@endif</div>
    </div>
    <div class="shrink-0 text-right">
        <div class="text-xs text-muted">{{ __('Qalıq borc (mühasibat)') }}{{ ! $document && $year ? ' · '.$year : '' }}</div>
        <div class="text-lg font-semibold tabular-nums {{ $docTotal < 0 ? 'neg' : '' }}">{{ Fmt::money(abs($docTotal)) }}</div>
    </div>
</div>

<div class="space-y-5 px-5 py-4">
    @if ($settlement)
        {{-- dövriyyə vərəqindəki məbləğ qalıqdır: ödənilən + qalıq = sənədin tam dəyəri --}}
        <section class="overflow-hidden rounded-lg border border-line">
            <table class="tbl">
                <tbody>
                <tr><td>{{ $kind === 'customer' ? __('Bankdan daxil olub (:from – :to)', ['from' => Fmt::period($settlement['from']), 'to' => Fmt::period($settlement['to'])]) : __('Bankdan ödənilib (:from – :to)', ['from' => Fmt::period($settlement['from']), 'to' => Fmt::period($settlement['to'])]) }}</td><td class="num">{{ Fmt::money($settlement['paid']) }}</td></tr>
                <tr><td>{{ __('+ Qalıq borc (mühasibat, il sonuna)') }}</td><td class="num">{{ Fmt::money($settlement['remaining']) }}</td></tr>
                @if ($settlement['paid'] < 0.005 && $settlement['bank_period'] > 0.005)
                    <tr><td colspan="2" class="wrap text-xs text-muted">{{ __('Bu dövrdə bank hərəkəti (:sum) var, amma sistemlə müqayisə göstərir ki, o, başqa sənədlərə aiddir — bu sənəd ödənilməyib.', ['sum' => Fmt::money($settlement['bank_period'])]) }}</td></tr>
                @endif
                <tr class="font-semibold"><td>{{ __('= Sənədin tam dəyəri') }}</td><td class="num">{{ Fmt::money($settlement['total']) }}</td></tr>
                @if ($settlement['system'] !== null)
                    <tr><td>{{ __('Sistemdə: :ref', ['ref' => $settlement['system_ref']]) }}</td><td class="num">{{ Fmt::money($settlement['system']) }}</td></tr>
                    <tr @class(['font-semibold', 'pos' => abs($settlement['diff']) < 1, 'warn' => abs($settlement['diff']) >= 1])><td>{{ __('Fərq') }}</td><td class="num">{{ abs($settlement['diff']) < 0.005 ? '✓' : Fmt::money($settlement['diff']) }}</td></tr>
                @endif
                </tbody>
            </table>
        </section>
    @endif

    {{-- sistemdəki qarşılığı --}}
    @if ($kind !== 'other')
        <section>
            <h3 class="mb-2 text-sm font-semibold">
                {{ $kind === 'customer' ? __('Sistemdə satış sifarişləri (sənəd tarixi ± 3 gün)') : __('Sistemdə idxal partiyaları (sənəd tarixi ± 10 gün)') }}
                @if ($kind === 'customer')
                    <span class="font-normal text-muted">·
                        @if ($customer)<a href="{{ route('customers.show', $customer->id) }}" class="cell-link">{{ $customer->name }}</a>@else{{ __('sistemdə müştəri tapılmadı') }}@endif
                    </span>
                @endif
            </h3>
            <div class="tbl-wrap rounded-lg border border-line">
                <table class="tbl">
                    <thead><tr><th>{{ __('Sənəd (mühasibat)') }}</th><th>{{ __('Tarix') }}</th><th>{{ $kind === 'customer' ? __('Sifariş') : __('Partiya') }}</th><th class="num">{{ __('Sistemdə') }}</th><th class="num">{{ __('Qalıq borc (mühasibat)') }}</th></tr></thead>
                    <tbody>
                    @forelse ($matches as $m)
                        <tr @class(['font-semibold' => $m->exact])>
                            <td class="wrap text-xs text-muted">{{ $m->document }}</td>
                            <td>{{ Fmt::date($m->date) }}</td>
                            <td class="wrap">
                                <a href="{{ $kind === 'customer' ? route('orders.show', $m->id) : route('purchases.show', $m->id) }}" class="cell-link">{{ $m->ref }}</a>
                                @if ($m->exact)<span class="badge badge-pos">{{ __('məbləğ eynidir') }}</span>@endif
                            </td>
                            <td class="num">{{ Fmt::money($m->amount) }}</td>
                            <td class="num text-muted">{{ Fmt::money(abs($m->doc_amount)) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-muted">{{ __('Sənəd tarixinə yaxın sistem qeydi tapılmadı.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($kind === 'supplier')<div class="mt-1 text-xs text-muted">{{ __('Partiyanın məbləği maya ilə (03 faylı) — mühasibatdakı sənəd ƏDV və gömrük xərcləri ilə fərqlənə bilər.') }}</div>@endif
        </section>
    @endif

    <section>
        <h3 class="mb-2 text-sm font-semibold">{{ __('Bank hərəkətləri (Cash Flow)') }}</h3>
        <div class="tbl-wrap max-h-64 rounded-lg border border-line">
            <table class="tbl">
                <thead><tr><th>{{ __('Ay') }}</th><th>{{ __('Kontragent') }}</th><th>{{ __('Bölmə') }}</th><th class="num">{{ __('Məbləğ') }}</th></tr></thead>
                <tbody>
                @forelse ($bank as $f)
                    <tr>
                        <td>{{ Fmt::period($f->period) }}</td>
                        <td class="wrap">{{ $f->counterparty }}</td>
                        <td><span class="badge {{ $f->section === 'income' ? 'badge-pos' : 'badge-neg' }}">{{ $sectionLabels[$f->section] ?? $f->section }}</span></td>
                        <td class="num">{{ Fmt::money($f->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-muted">{{ __('Bu kontragentlə bank hərəkəti yoxdur.') }}</td></tr>
                @endforelse
                </tbody>
                @if ($bank->isNotEmpty())
                    <tfoot><tr><td colspan="3">{{ __('Mədaxil / ödəniş') }}</td><td class="num">{{ Fmt::money($bank->where('section', 'income')->sum('amount')) }} / {{ Fmt::money($bank->where('section', 'payment')->sum('amount')) }}</td></tr></tfoot>
                @endif
            </table>
        </div>
    </section>

    <section>
        <h3 class="mb-2 text-sm font-semibold">{{ __('Mühasibatda bütün sənədləri (hesab :code)', ['code' => $code]) }}</h3>
        <div class="tbl-wrap max-h-64 rounded-lg border border-line">
            <table class="tbl">
                <thead><tr><th>{{ __('İl') }}</th><th>{{ __('Sənəd') }}</th><th class="num">{{ __('Debet') }}</th><th class="num">{{ __('Kredit') }}</th></tr></thead>
                <tbody>
                @foreach ($documents as $d)
                    <tr @class(['font-semibold' => $d->document === $document])>
                        <td>{{ $d->year }}</td>
                        <td class="wrap">{{ $d->document }}</td>
                        <td class="num">{{ (float) $d->debit != 0 ? Fmt::money($d->debit) : '' }}</td>
                        <td class="num">{{ (float) $d->credit != 0 ? Fmt::money($d->credit) : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-1 text-xs text-muted">{{ __('Dövriyyə vərəqindəki məbləğ sənədin dəyəri deyil, il sonuna qalıq borcdur (ödənişlər çıxılıb). Mal sətirləri sistemdəki sifariş / partiyada.') }}</div>
    </section>
</div>
