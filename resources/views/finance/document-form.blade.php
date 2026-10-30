@php
    use App\Support\Fmt;
    $isCash = str_starts_with($type, 'cash_');
    $presetRegister = old('cash_register_id', $preset['register']);
    $config = ['customers' => $customers, 'registers' => $registers, 'customer' => (int) old('customer_id', $preset['customer']) ?: null, 'register' => $presetRegister ? (int) $presetRegister : null,
        'accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'currency' => $a->currency])->values(), 'from' => (int) old('bank_account_id', $preset['account']) ?: null, 'to' => (int) old('to_bank_account_id') ?: null];
@endphp
<x-layouts.app :title="$title">
    <script>
        function financeForm(config) {
            const customers = new Map(config.customers.map((c) => [c.id, c]));
            const accounts = new Map(config.accounts.map((a) => [a.id, a]));
            const norm = (s) => String(s ?? '').toLocaleLowerCase('az').trim();
            const c = customers.get(config.customer);
            return {
                customer_id: c ? c.id : null, customerQuery: c ? c.name + ' · ' + c.code : '', open: false,
                register: config.register, from: config.from, to: config.to,
                matches() { const q = norm(this.customerQuery); return config.customers.filter((x) => !q || norm(x.name + ' ' + x.code).includes(q)).slice(0, 30); },
                pick(x) {
                    this.customer_id = x.id; this.customerQuery = x.name + ' · ' + x.code; this.open = false;
                    // ödəniş müştərinin nümayəndəsinin kassasına
                    const reg = config.registers.find((r) => r.manager_id && r.manager_id === x.manager_id);
                    if (reg && !config.register) this.register = reg.id;
                },
                currencyDiffers() { const a = accounts.get(Number(this.from)), b = accounts.get(Number(this.to)); return a && b && a.currency !== b.currency; },
                cur(id) { return accounts.get(Number(id))?.currency ?? ''; },
            };
        }
    </script>
    <form method="POST" action="{{ route('finance.documents.store') }}" class="flex flex-col gap-4" x-data="financeForm(@js($config))">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        <div class="flex flex-wrap gap-2">
            <a href="{{ $isCash ? route('finance.registers') : route('finance.banks') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
            @foreach ($types as $key => $label)
                @continue((in_array($key, ['cash_opening', 'bank_opening', 'bank_transfer'], true) && ! auth()->user()->can('finance.manage')) || $key === 'supplier_payment')
                <a href="{{ route('finance.documents.create', ['type' => $key]) }}" @class(['btn btn-sm', 'btn-primary' => $key === $type])>{{ $label }}</a>
            @endforeach
        </div>
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ $types[$type] }}</h2>
                <div class="card-sub">{{ [
                    'cash_opening' => __('Kassanın bu tarixə ilkin qalığı: əvvəlki hərəkətlər nəzərə alınmır (başqa proqramdan keçid üçün).'),
                    'cash_receipt' => __('Müştəridən alınan nağd ödəniş: müştərinin borcu azalır, pul seçilmiş kassada (nümayəndənin kassa kodunda) oturur.'),
                    'cash_transfer' => __('Bir kassadan digərinə pul: məs. ekspeditor kassasından əsas kassaya təhvil.'),
                    'bank_opening' => __('Bank hesabının bu tarixə ilkin qalığı: əvvəlki əməliyyatlar nəzərə alınmır.'),
                    'bank_transfer' => __('Bir bank hesabından digərinə köçürmə; valyutalar fərqlidirsə qəbul edilən məbləğ yazılır.'),
                ][$type] }}</div></div></div>
            <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div><label class="label" for="f-date">{{ __('Tarix') }}</label><input id="f-date" type="date" name="date" required class="input" value="{{ old('date', now()->toDateString()) }}"></div>
                <div><label class="label" for="f-wp">{{ __('İş yeri') }}</label>
                    <select id="f-wp" name="workplace_id" class="select" required>@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected((int) old('workplace_id', $defaultWorkplace) === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>

                @if ($type === 'cash_receipt')
                    <div class="relative sm:col-span-2" @click.outside="open = false">
                        <label class="label" for="f-cust">{{ __('Müştəri') }}</label>
                        <input type="hidden" name="customer_id" :value="customer_id">
                        <input id="f-cust" class="input" autocomplete="off" required placeholder="{{ __('Ad və ya kod…') }}" x-model="customerQuery" @focus="open = true" @input="open = true; customer_id = null">
                        <ul x-show="open" x-cloak class="card absolute z-30 mt-1 max-h-64 w-full overflow-auto py-1 text-sm">
                            <template x-for="x in matches()" :key="x.id"><li><button type="button" class="flex w-full justify-between gap-3 px-3 py-1.5 text-left hover:bg-[var(--surface-2)]" @click="pick(x)"><span x-text="x.name"></span><span class="text-xs text-muted" x-text="x.code"></span></button></li></template>
                        </ul>
                    </div>
                @endif

                @if ($isCash)
                    <div class="sm:col-span-2"><label class="label" for="f-reg">{{ $type === 'cash_transfer' ? __('Göndərən kassa') : __('Kassa') }}</label>
                        <select id="f-reg" name="cash_register_id" class="select" required x-model.number="register">
                            <option value="">—</option>
                            @foreach ($registers as $r)<option value="{{ $r->id }}">{{ $r->code }} — {{ $r->name }} ({{ Fmt::number($balances[$r->id] ?? 0, 2) }} {{ $r->currency }})</option>@endforeach
                        </select></div>
                    @if ($type === 'cash_transfer')
                        <div class="sm:col-span-2"><label class="label" for="f-reg2">{{ __('Qəbul edən kassa') }}</label>
                            <select id="f-reg2" name="to_cash_register_id" class="select" required>
                                @foreach ($registers as $r)<option value="{{ $r->id }}" @selected((int) old('to_cash_register_id') === $r->id || (! old('to_cash_register_id') && $loop->first))>{{ $r->code }} — {{ $r->name }}</option>@endforeach
                            </select></div>
                    @endif
                @else
                    <div class="sm:col-span-2"><label class="label" for="f-acc">{{ $type === 'bank_transfer' ? __('Göndərən hesab') : __('Bank hesabı') }}</label>
                        <select id="f-acc" name="bank_account_id" class="select" required x-model.number="from">
                            <option value="">—</option>
                            @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->bank_name ? $a->bank_name.' · ' : '' }}{{ $a->name }} ({{ $a->currency }})</option>@endforeach
                        </select></div>
                    @if ($type === 'bank_transfer')
                        <div class="sm:col-span-2"><label class="label" for="f-acc2">{{ __('Qəbul edən hesab') }}</label>
                            <select id="f-acc2" name="to_bank_account_id" class="select" required x-model.number="to">
                                <option value="">—</option>
                                @foreach ($accounts as $a)<option value="{{ $a->id }}">{{ $a->bank_name ? $a->bank_name.' · ' : '' }}{{ $a->name }} ({{ $a->currency }})</option>@endforeach
                            </select></div>
                    @endif
                @endif

                <div><label class="label" for="f-amount">{{ in_array($type, ['cash_opening', 'bank_opening'], true) ? __('İlkin qalıq') : __('Məbləğ') }} <span x-text="cur(from)"></span></label>
                    <input id="f-amount" type="number" step="0.01" min="0" name="amount" required class="input" value="{{ old('amount') }}"></div>
                @if ($type === 'bank_transfer')
                    <div x-show="currencyDiffers()" x-cloak><label class="label" for="f-to-amount">{{ __('Qəbul edilən məbləğ') }} <span x-text="cur(to)"></span></label>
                        <input id="f-to-amount" type="number" step="0.01" min="0" name="to_amount" class="input" value="{{ old('to_amount') }}"></div>
                @endif
                <div class="sm:col-span-2"><label class="label" for="f-note">{{ __('Qeyd') }}</label><input id="f-note" name="note" maxlength="1000" class="input" value="{{ old('note') }}"></div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line p-4">
                <a href="{{ $isCash ? route('finance.registers') : route('finance.banks') }}" class="btn">{{ __('Ləğv et') }}</a>
                <button class="btn btn-primary">{{ __('Sənədi yarat') }}</button>
            </div>
        </section>
    </form>
</x-layouts.app>
