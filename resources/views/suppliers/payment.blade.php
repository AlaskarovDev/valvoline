<x-layouts.app :title="$title">
    <script>
        function supplierPayment(config) {
            const suppliers = new Map(config.suppliers.map((s) => [s.id, s]));
            const registers = new Map(config.registers.map((r) => [r.id, r]));
            const accounts = new Map(config.accounts.map((a) => [a.id, a]));
            return {
                supplier_id: config.supplier, source: config.source, register: config.registers[0]?.id ?? null, account: config.accounts[0]?.id ?? null,
                supplier() { return suppliers.get(Number(this.supplier_id)) ?? null; },
                payCurrency() { return this.source === 'cash' ? (registers.get(Number(this.register))?.currency ?? 'AZN') : (accounts.get(Number(this.account))?.currency ?? 'AZN'); },
                differs() { return this.supplier() && this.supplier().currency !== this.payCurrency(); },
                num: (v) => window.fmt.number(v, 2),
            };
        }
    </script>
    <form method="POST" action="{{ route('supplier-payments.store') }}" class="flex flex-col gap-4" x-data="supplierPayment(@js($config))">
        @csrf
        <div><a href="{{ route('suppliers.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Təchizatçılar') }}</a></div>
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ __('Təchizatçıya ödəniş') }}</h2><div class="card-sub">{{ __('Kassadan və ya bank hesabından ödəniş: təchizatçıya borcumuz azalır, kassa / hesab qalığı azalır.') }}</div></div></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="sp-date">{{ __('Tarix') }}</label><input id="sp-date" type="date" name="date" required class="input" value="{{ old('date', now()->toDateString()) }}" max="{{ now()->toDateString() }}"></div>
                <div><label class="label" for="sp-wp">{{ __('İş yeri') }}</label><select id="sp-wp" name="workplace_id" class="select">@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected((int) old('workplace_id', $defaultWorkplace) === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
                <div class="sm:col-span-2"><label class="label" for="sp-sup">{{ __('Təchizatçı') }}</label>
                    <select id="sp-sup" name="supplier_id" class="select" x-model.number="supplier_id" required>
                        <option value="">—</option>
                        <template x-for="s in @js($config['suppliers'])" :key="s.id"><option :value="s.id" :selected="s.id === supplier_id" x-text="s.name + ' · ' + s.code + ' — ' + @js(__('borcumuz')) + ': ' + window.fmt.number(s.balance, 2) + ' ' + s.currency"></option></template>
                    </select></div>
                <div><span class="label">{{ __('Mənbə') }}</span>
                    <div class="flex gap-3 pt-2 text-sm">
                        <label class="flex items-center gap-1.5"><input type="radio" name="source" value="bank" x-model="source"> {{ __('Bank hesabı') }}</label>
                        <label class="flex items-center gap-1.5"><input type="radio" name="source" value="cash" x-model="source"> {{ __('Kassa') }}</label>
                    </div></div>
                <div x-show="source === 'bank'"><label class="label" for="sp-acc">{{ __('Bank hesabı') }}</label>
                    <select id="sp-acc" name="bank_account_id" class="select" x-model.number="account" :disabled="source !== 'bank'">@foreach ($config['accounts'] as $a)<option value="{{ $a->id }}">{{ $a->name }} ({{ $a->currency }})</option>@endforeach</select></div>
                <div x-show="source === 'cash'" x-cloak><label class="label" for="sp-reg">{{ __('Kassa') }}</label>
                    <select id="sp-reg" name="cash_register_id" class="select" x-model.number="register" :disabled="source !== 'cash'">@foreach ($config['registers'] as $r)<option value="{{ $r->id }}">{{ $r->code }} — {{ $r->name }} ({{ $r->currency }})</option>@endforeach</select></div>
                <div><label class="label" for="sp-amount">{{ __('Ödənilən məbləğ') }} <span class="text-muted" x-text="'(' + payCurrency() + ')'"></span></label>
                    <input id="sp-amount" name="amount" type="number" step="0.01" min="0.01" required class="input" value="{{ old('amount') }}"></div>
                <div x-show="differs()" x-cloak><label class="label" for="sp-to">{{ __('Borcdan silinən məbləğ') }} <span class="text-muted" x-text="'(' + (supplier()?.currency ?? '') + ')'"></span></label>
                    <input id="sp-to" name="to_amount" type="number" step="0.01" min="0.01" class="input" value="{{ old('to_amount') }}" :required="differs()"></div>
                <div class="sm:col-span-2 lg:col-span-4"><label class="label" for="sp-note">{{ __('Qeyd') }}</label><input id="sp-note" name="note" maxlength="1000" class="input" value="{{ old('note') }}" placeholder="{{ __('Məs.: INV-77 üzrə ödəniş') }}"></div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line p-4">
                <a href="{{ route('suppliers.index') }}" class="btn">{{ __('Ləğv et') }}</a>
                <button class="btn btn-primary" :disabled="!supplier_id">{{ __('Ödənişi yaz') }}</button>
            </div>
        </section>
    </form>
</x-layouts.app>
