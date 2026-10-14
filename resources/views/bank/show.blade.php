@php
    use App\Support\Fmt;
    $typeClass = ['expense' => 'badge-neg', 'cogs' => 'badge-primary', 'tax' => 'badge-warn', 'financing' => 'badge-warn', 'revenue' => 'badge-pos', 'uncertain' => ''];
@endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('bank.index', ['year' => $year]) }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Bank hesabları') }}</a>
        <span class="text-xs text-muted">{{ $account->bank ?: '—' }} · {{ $kinds[$account->kind] ?? $account->kind }} · {{ $account->currency }}@if ($account->iban) · <span class="font-mono">{{ $account->iban }}</span>@endif</span>
        @if (count($years) > 0)
            <form method="GET" class="ml-auto flex items-center gap-2 text-xs text-muted">
                <label for="acc-year">{{ __('İl') }}</label>
                <select id="acc-year" name="year" class="select h-[30px] w-auto text-xs" onchange="this.form.submit()">
                    @foreach ($years as $y)<option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>@endforeach
                </select>
            </form>
        @endif
    </div>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Qalıq (mühasibat faylı)')" :value="$fileLatest ? Fmt::money($fileLatest[1]) : '—'" :meta="$fileLatest ? __(':period sonuna', ['period' => Fmt::period($fileLatest[0])]).($account->currency !== 'AZN' ? ' · '.__('AZN ekv.') : '') : __('faylda bu hesab yoxdur')"/>
        <x-kpi :label="__('Mədaxil')" :value="Fmt::money($sumIn)" tone="pos" :meta="__(':year · seçilmiş filtr', ['year' => $year])"/>
        <x-kpi :label="__('Məxaric')" :value="Fmt::money($sumOut)" tone="neg" :meta="__(':year · seçilmiş filtr', ['year' => $year])"/>
        <x-kpi :label="__('Əməliyyat sayı')" :value="Fmt::number($transactions->total())" :meta="__('çıxarış + əl ilə')"/>
    </div>

    @if ($monthly->isNotEmpty())
        <section class="card overflow-hidden">
            <div class="card-head"><div>
                <h2 class="card-title">{{ __('Ay üzrə') }} — {{ $year }}</h2>
                <div class="card-sub">{{ __('Ay sonu qalığı mühasibat faylından; dövriyyə və çıxarış qalığı yüklənmiş əməliyyatlardan.') }}</div>
            </div></div>
            <div class="tbl-wrap">
                <table class="tbl">
                    <thead><tr><th>{{ __('Ay') }}</th><th class="num">{{ __('Mədaxil') }}</th><th class="num">{{ __('Məxaric') }}</th><th class="num">{{ __('Əməliyyat') }}</th><th class="num">{{ __('Çıxarışa görə qalıq') }}</th><th class="num">{{ __('Mühasibat faylı') }}</th><th class="num">{{ __('Fərq') }}</th></tr></thead>
                    <tbody>
                    @foreach ($monthly as $m)
                        @php $diff = $m->statement !== null && $m->file !== null ? $m->statement - $m->file : null; @endphp
                        <tr>
                            <td>{{ Fmt::period($m->period) }}</td>
                            <td class="num {{ $m->in > 0 ? 'pos' : 'text-faint' }}">{{ $m->in > 0 ? Fmt::money($m->in) : '—' }}</td>
                            <td class="num {{ $m->out > 0 ? 'neg' : 'text-faint' }}">{{ $m->out > 0 ? Fmt::money($m->out) : '—' }}</td>
                            <td class="num text-muted">{{ $m->n ?: '—' }}</td>
                            <td class="num">{{ $m->statement === null ? '—' : Fmt::money($m->statement) }}</td>
                            <td class="num">{{ $m->file === null ? '—' : Fmt::money($m->file) }}</td>
                            <td class="num {{ $diff !== null && abs($diff) > 0.01 ? 'warn' : 'text-faint' }}">{{ $diff === null ? '—' : (abs($diff) > 0.01 ? Fmt::money($diff) : '✓') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="card overflow-hidden">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ __('Əməliyyatlar') }}</h2>
                <div class="card-sub">{{ __('Növ kontragent adına görə avtomatik təyin olunur (Cash Flow təsnifatı ilə eyni). Əməliyyatlar məlumat üçündür — P&L-ə mühasibat Cash Flow-dan düşür.') }}</div>
            </div>
        </div>
        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
            <input type="hidden" name="year" value="{{ $year }}">
            <label class="sr-only" for="tx-q">{{ __('Axtarış') }}</label>
            <input id="tx-q" name="q" value="{{ $q }}" placeholder="{{ __('Kontragent, təyinat, sənəd…') }}" class="input h-[34px] min-w-0 flex-1 text-xs sm:h-[30px] sm:w-64 sm:flex-none">
            <label class="sr-only" for="tx-dir">{{ __('İstiqamət') }}</label>
            <select id="tx-dir" name="dir" class="select h-[34px] w-auto text-xs sm:h-[30px]">
                <option value="">{{ __('Hamısı') }}</option>
                <option value="in" @selected($dir === 'in')>{{ __('Mədaxil') }}</option>
                <option value="out" @selected($dir === 'out')>{{ __('Məxaric') }}</option>
            </select>
            <button class="btn btn-sm btn-primary">{{ __('Tətbiq et') }}</button>
        </form>

        {{-- Telefon: kartlar --}}
        <ul class="divide-y divide-line md:hidden">
            @forelse ($transactions as $t)
                <li class="px-4 py-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-medium">{{ $t->counterparty ?: '—' }}</div>
                            <div class="text-xs text-muted">{{ Fmt::date($t->date) }} · <span class="badge {{ $typeClass[$t->type] ?? '' }}">{{ $t->type_label }}</span></div>
                            @if ($t->description && $t->description !== $t->counterparty)<div class="mt-1 text-xs text-muted">{{ $t->description }}</div>@endif
                        </div>
                        <div class="shrink-0 font-semibold tabular-nums {{ $t->amount_in > 0 ? 'pos' : 'neg' }}">{{ $t->amount_in > 0 ? '+'.Fmt::money($t->amount_in) : '−'.Fmt::money($t->amount_out) }}</div>
                    </div>
                </li>
            @empty
                <li class="px-4 py-8 text-center text-muted">{{ __('Bu il üçün əməliyyat yoxdur.') }}</li>
            @endforelse
        </ul>

        {{-- Planşet / kompüter: cədvəl --}}
        <div class="tbl-wrap hidden max-h-[75vh] md:block">
            <table class="tbl">
                <thead><tr>
                    <th>{{ __('Tarix') }}</th><th class="min-w-48">{{ __('Kontragent') }}</th><th class="min-w-64">{{ __('Təyinat') }}</th><th>{{ __('Növ') }}</th>
                    <th class="num">{{ __('Mədaxil') }}</th><th class="num">{{ __('Məxaric') }}</th><th class="num">{{ __('Qalıq') }}</th><th>{{ __('Mənbə') }}</th><th></th>
                </tr></thead>
                <tbody>
                @forelse ($transactions as $t)
                    <tr>
                        <td>{{ Fmt::date($t->date) }}</td>
                        <td class="wrap">{{ $t->counterparty ?: '—' }}</td>
                        <td class="wrap text-xs text-muted">{{ $t->description }}@if ($t->doc_no)<div class="text-faint">№ {{ $t->doc_no }}</div>@endif</td>
                        <td><span class="badge {{ $typeClass[$t->type] ?? '' }}">{{ $t->type_label }}</span></td>
                        <td class="num {{ $t->amount_in > 0 ? 'pos' : 'text-faint' }}">{{ $t->amount_in > 0 ? Fmt::money($t->amount_in) : '' }}</td>
                        <td class="num {{ $t->amount_out > 0 ? 'neg' : 'text-faint' }}">{{ $t->amount_out > 0 ? Fmt::money($t->amount_out) : '' }}</td>
                        <td class="num text-muted">{{ $t->balance !== null ? Fmt::money($t->balance) : '' }}</td>
                        <td class="text-xs text-muted" title="{{ $t->file_name }}">{{ $t->source === 'statement' ? __('çıxarış') : __('əl ilə') }}</td>
                        <td>
                            <form method="POST" action="{{ route('bank.tx.destroy', $t->id) }}" onsubmit="return confirm(@js(__('Əməliyyat silinsin?')))">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm" aria-label="{{ __('Sil') }}" title="{{ __('Sil') }}">✕</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-8 text-center text-muted">{{ __('Bu il üçün əməliyyat yoxdur. Aşağıdan bank çıxarışını yükləyin və ya əməliyyatı əl ilə əlavə edin.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($transactions->hasPages())<div class="border-t border-line px-4 py-3">{{ $transactions->links() }}</div>@endif
    </section>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <section class="card">
            <div class="card-head"><div>
                <h2 class="card-title">{{ __('Bank çıxarışını yüklə') }}</h2>
                <div class="card-sub">{{ __('Excel (.xlsx, .xls) və ya CSV. Başlıqda «Tarix» və «Mədaxil / Məxaric» (və ya «Kredit / Debet», «Məbləğ») olmalıdır. Təkrar yükləmədə eyni sətirlər ötürülür.') }}</div>
            </div></div>
            <form method="POST" action="{{ route('bank.import', $account->id) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3 p-4">
                @csrf
                <div class="min-w-0 flex-1"><label class="label" for="st-file">{{ __('Fayl') }}</label><input id="st-file" type="file" name="file" required accept=".xlsx,.xls,.csv" class="input"></div>
                <button class="btn btn-primary">{{ __('Yüklə') }}</button>
                @error('file')<div class="w-full text-sm neg">{{ $message }}</div>@enderror
            </form>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Əməliyyat əlavə et') }}</h2></div>
            <form method="POST" action="{{ route('bank.tx.store', $account->id) }}" class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-4">
                @csrf
                <div><label class="label" for="tx-date">{{ __('Tarix') }}</label><input id="tx-date" type="date" name="date" required class="input" value="{{ old('date', now()->toDateString()) }}"></div>
                <div><label class="label" for="tx-direction">{{ __('İstiqamət') }}</label>
                    <select id="tx-direction" name="direction" class="select"><option value="in">{{ __('Mədaxil') }}</option><option value="out" @selected(old('direction') === 'out')>{{ __('Məxaric') }}</option></select></div>
                <div class="col-span-2"><label class="label" for="tx-cp">{{ __('Kontragent') }}</label><input id="tx-cp" name="counterparty" required class="input" value="{{ old('counterparty') }}"></div>
                <div><label class="label" for="tx-amount">{{ __('Məbləğ') }}</label><input id="tx-amount" type="number" step="0.01" min="0.01" name="amount" required class="input" value="{{ old('amount') }}"></div>
                <div><label class="label" for="tx-doc">{{ __('Sənəd №') }}</label><input id="tx-doc" name="doc_no" class="input" value="{{ old('doc_no') }}"></div>
                <div class="col-span-2"><label class="label" for="tx-desc">{{ __('Təyinat') }}</label><input id="tx-desc" name="description" class="input" value="{{ old('description') }}"></div>
                <div class="col-span-2 sm:col-span-4"><button class="btn btn-primary">{{ __('Əlavə et') }}</button>
                    @if ($errors->any() && ! $errors->has('file'))<span class="ml-2 text-sm neg">{{ $errors->first() }}</span>@endif</div>
            </form>
        </section>
    </div>

    <section class="card" x-data="{ open: false }">
        <button type="button" class="card-head w-full text-left" @click="open = !open" :aria-expanded="open">
            <h2 class="card-title">{{ __('Hesab məlumatları') }}</h2><span class="card-sub" x-text="open ? '−' : '+'">+</span>
        </button>
        <form x-show="open" x-cloak method="POST" action="{{ route('bank.update', $account->id) }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-3">
            @csrf @method('PUT')
            <div><label class="label" for="ed-name">{{ __('Ad') }}</label><input id="ed-name" name="name" required class="input" value="{{ $account->name }}"></div>
            <div><label class="label" for="ed-bank">{{ __('Bank') }}</label><input id="ed-bank" name="bank" class="input" value="{{ $account->bank }}"></div>
            <div><label class="label" for="ed-iban">IBAN</label><input id="ed-iban" name="iban" class="input font-mono" value="{{ $account->iban }}"></div>
            <div><label class="label" for="ed-cur">{{ __('Valyuta') }}</label>
                <select id="ed-cur" name="currency" class="select">@foreach (['AZN', 'USD', 'EUR', 'RUB', 'GBP', 'TRY'] as $c)<option @selected($account->currency === $c)>{{ $c }}</option>@endforeach</select></div>
            <div><label class="label" for="ed-kind">{{ __('Növ') }}</label>
                <select id="ed-kind" name="kind" class="select">@foreach ($kinds as $k => $label)<option value="{{ $k }}" @selected($account->kind === $k)>{{ $label }}</option>@endforeach</select></div>
            <div class="flex items-end gap-2 pb-2"><input type="hidden" name="is_active" value="0"><input id="ed-active" type="checkbox" name="is_active" value="1" @checked($account->is_active)><label for="ed-active" class="text-sm">{{ __('İstifadədədir') }}</label></div>
            <div class="sm:col-span-3"><label class="label" for="ed-note">{{ __('Qeyd') }}</label><input id="ed-note" name="note" class="input" value="{{ $account->note }}"></div>
            <div class="sm:col-span-3"><button class="btn btn-primary">{{ __('Saxla') }}</button></div>
        </form>
    </section>
</x-layouts.app>
