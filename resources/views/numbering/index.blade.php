<x-layouts.app :title="$title">
    <script>
        function numberingRule(config) {
            return {
                pattern: config.pattern, mode: config.mode, number: config.preview, parts: config.parts, errors: [], timer: null,
                refresh() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(async () => {
                        const r = await fetch(config.url + '?pattern=' + encodeURIComponent(this.pattern) + '&mode=' + this.mode, { headers: { Accept: 'application/json' } });
                        if (!r.ok) return;
                        const d = await r.json();
                        this.number = d.number; this.parts = d.parts; this.errors = d.errors;
                    }, 250);
                },
                insert(token) { this.pattern += '{' + token + '}'; this.refresh(); },
            };
        }
    </script>

    <section class="card">
        <div class="card-head"><div><h2 class="card-title">{{ __('Nömrələmə servisi') }}</h2>
            <div class="card-sub">{{ __('Bütün sənəd nömrələri və kodlar buradakı qaydalarla verilir. Hər sənəd növü üçün öz şablonunu qurun: sabit mətn + tarix + iş yeri / anbar / nümayəndə kodu + sıra nömrəsi.') }}</div></div></div>
        <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
            <div>
                <div class="label">{{ __('Şablon hissələri') }}</div>
                <table class="w-full text-sm">
                    @foreach ($tokens as $token => $meaning)
                        <tr class="border-b border-line"><td class="py-1 pr-3 font-mono text-primary-text">{{ '{'.$token.'}' }}</td><td class="py-1 pr-3">{{ $meaning }}</td>
                            <td class="py-1 font-mono text-xs text-muted">{{ $token === 'SEQ' ? '1, 2, 3 …' : ($sample[$token] !== '' ? $sample[$token] : '—') }}</td></tr>
                    @endforeach
                    <tr><td class="py-1 pr-3 font-mono text-primary-text">{SEQ:4}</td><td class="py-1" colspan="2">{{ __('sıra nömrəsi 4 rəqəmə qədər sıfırla: 0001, 0002 …') }}</td></tr>
                </table>
            </div>
            <div class="text-sm">
                <div class="label">{{ __('Nümunə') }}</div>
                <p class="mb-2"><span class="font-mono">S{IYN}{ANBN}.{DD}{MM}{YY}.{SEQ:4}</span> → <span class="font-mono font-semibold">S{{ $sample['IYN'] }}{{ $sample['ANBN'] }}.{{ $sample['DD'] }}{{ $sample['MM'] }}{{ $sample['YY'] }}.0001</span></p>
                <ul class="list-inside list-disc text-muted">
                    <li>{{ __('«Avtomatik» — nömrəni sistem verir, formada dəyişmək olmur.') }}</li>
                    <li>{{ __('«Avtomatik, dəyişmək olar» — sistem növbəti nömrəni təklif edir, istifadəçi dəyişə bilər.') }}</li>
                    <li>{{ __('«Əl ilə» — nömrəni istifadəçi yazır (şablon nümunə kimi göstərilir).') }}</li>
                    <li>{{ __('Sıra nömrəsi seçilən qaydaya görə yenidən 1-dən başlayır; «Növbəti nömrə» ilə istənilən rəqəmdən davam etdirmək olar.') }}</li>
                    <li>{{ __('Eyni nömrə iki dəfə verilmir: mövcud nömrə varsa növbətisi götürülür.') }}</li>
                </ul>
            </div>
        </div>
    </section>

    @foreach ($groups as $group => $groupLabel)
        <section class="card overflow-hidden">
            <div class="card-head"><h2 class="card-title">{{ $groupLabel }}</h2></div>
            <div class="divide-y divide-[var(--border)]">
                @foreach ($rules->where('group', $group) as $r)
                    <form id="{{ $r->key }}" method="POST" action="{{ route('numbering.update', $r->key) }}" class="grid grid-cols-1 gap-3 p-4 lg:grid-cols-[200px_minmax(0,1fr)_minmax(0,1fr)]"
                          x-data="numberingRule(@js(['pattern' => old('pattern', $r->pattern), 'mode' => $r->mode, 'preview' => $r->preview, 'parts' => $r->parts, 'url' => route('numbering.preview', $r->key)]))">
                        @csrf @method('PUT')
                        <div>
                            <div class="font-semibold">{{ $r->label }}</div>
                            <div class="mt-1 text-xs text-muted">{{ __('Növbəti') }}: <span class="font-mono font-semibold text-[var(--text)]">{{ $r->preview }}</span></div>
                            @if ($r->last)<div class="text-xs text-muted">{{ __('Sonuncu') }}: <span class="font-mono">{{ $r->last }}</span></div>@endif
                        </div>
                        <div class="flex flex-col gap-2">
                            <div>
                                <label class="label" for="pt-{{ $r->key }}">{{ __('Şablon') }}</label>
                                <input id="pt-{{ $r->key }}" name="pattern" class="input font-mono" maxlength="64" x-model="pattern" @input="refresh()" required>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach (array_keys($tokens) as $token)
                                        <button type="button" class="badge cursor-pointer hover:border-[var(--primary)]" @click="insert('{{ $token === 'SEQ' ? 'SEQ:4' : $token }}')">{{ '{'.($token === 'SEQ' ? 'SEQ:4' : $token).'}' }}</button>
                                    @endforeach
                                    <button type="button" class="badge cursor-pointer" @click="pattern = @js($r->default); refresh()" title="{{ __('Susmaya görə şablon') }}">↺ {{ $r->default }}</button>
                                </div>
                            </div>
                            <div class="rounded-lg bg-[var(--surface-2)] px-3 py-2 text-sm">
                                <div><span class="text-xs text-muted">{{ __('Nümunə nömrə') }}:</span> <span class="font-mono text-base font-semibold" x-text="number"></span></div>
                                <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                                    <template x-for="(p, i) in parts" :key="i"><span><span class="font-mono font-semibold" x-text="p[0]"></span> <span class="text-muted" x-text="'— ' + p[1]"></span></span></template>
                                </div>
                                <div class="mt-1 text-xs neg" x-show="errors.length" x-text="errors.join(' ')"></div>
                                @error('pattern_'.$r->key)<div class="mt-1 text-xs neg">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div><label class="label" for="md-{{ $r->key }}">{{ __('Rejim') }}</label>
                                <select id="md-{{ $r->key }}" name="mode" class="select" x-model="mode" @change="refresh()">@foreach ($modes as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                            <div><label class="label" for="rs-{{ $r->key }}">{{ __('Sıra yenidən başlasın') }}</label>
                                <select id="rs-{{ $r->key }}" name="reset" class="select">@foreach ($resets as $k => $l)<option value="{{ $k }}" @selected($r->reset === $k)>{{ $l }}</option>@endforeach</select></div>
                            <div><label class="label" for="nx-{{ $r->key }}">{{ __('Növbəti nömrə (istəyə görə)') }}</label>
                                <input id="nx-{{ $r->key }}" type="number" min="1" name="next" class="input" placeholder="{{ __('məs. 500') }}"></div>
                            <div><label class="label" for="nt-{{ $r->key }}">{{ __('Qeyd') }}</label><input id="nt-{{ $r->key }}" name="note" maxlength="255" class="input" value="{{ $r->note }}"></div>
                            <div class="sm:col-span-2 flex justify-end"><button class="btn btn-sm btn-primary" :disabled="errors.length > 0">{{ __('Yadda saxla') }}</button></div>
                        </div>
                    </form>
                @endforeach
            </div>
        </section>
    @endforeach
</x-layouts.app>
