@php use App\Support\Fmt; $open = $count->isOpen(); @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('stock-counts.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Sayım') }}</a>
        <span class="badge">{{ Fmt::date($count->date) }}</span>
        <span class="badge">{{ __('Anbar') }}: {{ $count->warehouse->name }}</span>
        <span class="badge">{{ $count->scope === 'full' ? __('Tam sayım') : __('Seçmə sayım') }}</span>
        @if ($count->completed_at)<span class="badge badge-pos">{{ __('Tamamlanıb') }}</span>@endif
        @if ($count->surplus)<a href="{{ route('stock-docs.show', $count->surplus) }}" class="badge badge-primary">{{ $count->surplus->doc_no }}</a>@endif
        @if ($count->shortage)<a href="{{ route('stock-docs.show', $count->shortage) }}" class="badge badge-primary">{{ $count->shortage->doc_no }}</a>@endif
        @if ($open)
            <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                <form method="POST" action="{{ route('stock-counts.complete', $count) }}" onsubmit="return confirm(@js(__('Sayım tamamlansın? Fərqlər üçün artıq / əskik sənədləri yaradılacaq.')))">@csrf
                    <button class="btn btn-sm btn-primary">{{ __('Sayımı tamamla') }}</button></form>
                <x-cancel-button :action="route('stock-counts.destroy', $count)" :confirm="__('Sayım ləğv edilsin? Sayılan miqdarlar sənədə çevrilməyəcək.')"/>
            </div>
        @endif
    </div>
    <x-cancelled-banner :doc="$count"/>

    @if ($open)
        <section class="card p-4" x-data="stockCount(@js(['url' => route('stock-counts.scan', $count), 'products' => $products]))" x-init="$refs.code.focus()">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1"><label class="label" for="sc-code">{{ __('Barkod skaneri') }}</label>
                    <input id="sc-code" x-ref="code" x-model="code" @keydown.enter.prevent="send(code, null)" autocomplete="off" inputmode="numeric" class="input font-mono" placeholder="{{ __('Barkodu oxudun və ya yazıb Enter basın') }}"></div>
                <div><label class="label" for="sc-mult">{{ __('Say') }}</label><input id="sc-mult" type="number" min="0" step="any" x-model.number="mult" class="input w-20 text-right"></div>
                <button type="button" class="btn" x-show="camera" @click="scanCamera()"><x-icon name="search" size="14"/> {{ __('Kamera ilə') }}</button>
            </div>
            <video x-ref="video" x-show="streaming" class="mt-3 max-h-64 w-full rounded-lg bg-black object-cover" playsinline muted></video>
            <div class="mt-2 text-sm" :class="ok ? 'pos' : 'neg'" x-text="msg" role="status" aria-live="polite"></div>
            <div class="mt-3"><label class="label" for="sc-search">{{ __('Barkodsuz mal: axtarış') }}</label>
                <input id="sc-search" x-model="q" class="input" placeholder="{{ __('kod və ya ad') }}" autocomplete="off">
                <ul class="mt-1 max-h-48 divide-y divide-line overflow-y-auto rounded-md border border-line" x-show="q.length > 1">
                    <template x-for="p in matches()" :key="p.id">
                        <li><button type="button" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-2" @click="send(null, p.id); q = ''"><span class="font-mono text-xs text-muted" x-text="p.code"></span> <span x-text="p.name"></span></button></li>
                    </template>
                </ul>
            </div>
            <ul class="mt-3 divide-y divide-line text-sm" x-show="recent.length">
                <template x-for="r in recent" :key="r.key">
                    <li class="flex items-center gap-2 py-1.5"><span class="font-mono text-xs text-muted" x-text="r.code"></span><span class="min-w-0 flex-1 truncate" x-text="r.name"></span>
                        <label class="sr-only" :for="'set-' + r.product_id">{{ __('Sayılan') }}</label>
                        <input :id="'set-' + r.product_id" type="number" min="0" step="any" :value="r.counted" @change="setQty(r, $event.target.value)" class="input h-8 w-24 text-right"></li>
                </template>
            </ul>
        </section>
        <script>
            function stockCount(cfg) {
                const token = document.querySelector('meta[name="csrf-token"]').content;
                return {
                    code: '', mult: 1, msg: '', ok: true, q: '', recent: [], streaming: false, camera: 'BarcodeDetector' in window && !!navigator.mediaDevices,
                    matches() { const q = this.q.toLowerCase(); return cfg.products.filter((p) => (p.code + ' ' + p.name).toLowerCase().includes(q)).slice(0, 20); },
                    async post(body) {
                        const r = await fetch(cfg.url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify(body) });
                        return r.ok ? r.json() : { ok: false, message: @js(__('Xəta — yenidən cəhd edin.')) };
                    },
                    remember(d) { this.recent = [{ ...d, key: d.product_id + '-' + Date.now() }, ...this.recent.filter((x) => x.product_id !== d.product_id)].slice(0, 30); },
                    async send(code, productId) {
                        if (!code && !productId) return;
                        const d = await this.post({ code, product_id: productId, qty: this.mult || 1, mode: 'add' });
                        this.ok = d.ok; this.msg = d.ok ? '✓ ' + d.code + ' — ' + d.name + ' · ' + fmt.number(d.counted, 2) : d.message;
                        if (d.ok) this.remember(d);
                        this.code = ''; this.$refs.code.focus();
                    },
                    async setQty(r, value) {
                        const d = await this.post({ product_id: r.product_id, qty: +value || 0, mode: 'set' });
                        this.ok = d.ok; this.msg = d.ok ? '✓ ' + d.code + ' = ' + fmt.number(d.counted, 2) : d.message;
                        if (d.ok) this.remember(d);
                    },
                    async scanCamera() {
                        const detector = new BarcodeDetector();
                        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                        const video = this.$refs.video; video.srcObject = stream; await video.play(); this.streaming = true;
                        const stop = () => { stream.getTracks().forEach((t) => t.stop()); this.streaming = false; };
                        const tick = async () => {
                            if (!this.streaming) return;
                            const codes = await detector.detect(video).catch(() => []);
                            if (codes.length) { stop(); await this.send(codes[0].rawValue, null); return; }
                            requestAnimationFrame(tick);
                        };
                        setTimeout(() => this.streaming && stop(), 20000);
                        tick();
                    },
                };
            }
        </script>
    @endif

    <section class="card overflow-hidden">
        <div class="card-head"><div><h2 class="card-title">{{ $open ? __('Müqayisə (bu anki sistem qalığı ilə)') : __('Nəticə') }}</h2>
            <div class="card-sub">{{ $open ? __('Səhifəni yeniləyəndə son skanlar da görünür.') : __('Sistem qalığı tamamlanma anındakıdır.') }}</div></div></div>
        <div class="tbl-wrap relative">
            <table class="tbl">
                <thead><tr><th>{{ __('Kod') }}</th><th>{{ __('Mal') }}</th><th class="num">{{ __('Sistemdə') }}</th><th class="num">{{ __('Sayılıb') }}</th><th class="num">{{ __('Fərq') }}</th><th class="num">{{ __('Dəyər') }}</th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td class="font-mono text-xs">{{ $r->code }}</td>
                            <td>{{ $r->name }}@unless ($r->is_counted) <span class="badge">{{ __('sayılmayıb') }}</span>@endunless</td>
                            <td class="num">{{ Fmt::qty($r->system) }}</td>
                            <td class="num">{{ Fmt::qty($r->counted) }}</td>
                            <td @class(['num font-semibold', 'pos' => $r->diff > 1e-6, 'neg' => $r->diff < -1e-6])>{{ $r->diff > 0 ? '+' : '' }}{{ Fmt::qty($r->diff) }}</td>
                            <td class="num">{{ Fmt::money($r->value) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-muted">{{ __('Hələ heç nə sayılmayıb.') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())<tfoot><tr><td colspan="4">{{ __('Cəmi fərq') }}</td><td class="num"></td><td class="num">{{ Fmt::money($rows->sum('value')) }}</td></tr></tfoot>@endif
            </table>
        </div>
    </section>
</x-layouts.app>
