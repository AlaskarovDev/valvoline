@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Sənəd növü') }}">
        @foreach (['cash_expense' => __('Kassa məxarici (xərc)'), 'cash_advance' => __('Avans (təhtəlhesab)'), 'advance_report' => __('Avans hesabatı')] as $k => $l)
            <a href="{{ route('cash-expenses.create', ['type' => $k]) }}" @class(['btn btn-sm', 'btn-primary' => $type === $k])>{{ $l }}</a>
        @endforeach
        <a href="{{ route('cash-expenses.advances') }}" class="btn btn-sm sm:ml-auto">{{ __('Təhtəlhesab şəxslər') }}</a>
    </nav>

    <form method="POST" action="{{ route('cash-expenses.store') }}" class="card flex flex-col gap-4 p-4"
          x-data="{ employees: @js($employees), employee: @js(old('employee_id', $preset['employee'])), lines: @js(old('lines', [['expense_category_id' => '', 'amount' => '', 'description' => '']])), returned: @js(old('returned', '')),
                    open() { const e = this.employees.find(x => x.id == this.employee); return e ? e.open : 0; },
                    spent() { return this.lines.reduce((s, l) => s + (+l.amount || 0), 0); } }">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        <p class="text-sm text-muted">
            @if ($type === 'cash_expense') {{ __('Pul kassadan çıxır və seçilmiş xərc maddəsi ilə Xərclər və mənfəət-zərər hesabatına düşür.') }}
            @elseif ($type === 'cash_advance') {{ __('Pul işçiyə hesabat şərti ilə verilir — xərc kimi yalnız avans hesabatında yazılır.') }}
            @else {{ __('İşçi avansla etdiyi xərcləri maddələr üzrə göstərir; istifadə olunmayan pul kassaya qaytarılır.') }} @endif
        </p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div><label class="label" for="e-date">{{ __('Tarix') }}</label><input id="e-date" type="date" name="date" value="{{ old('date', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required class="input"></div>
            <div><label class="label" for="e-wp">{{ __('İş yeri') }}</label><select id="e-wp" name="workplace_id" class="select">@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected((int) old('workplace_id', $defaultWorkplace) === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
            <div><label class="label" for="e-reg">{{ $type === 'advance_report' ? __('Qaytarılan pulun kassası') : __('Kassa') }}</label>
                <select id="e-reg" name="cash_register_id" class="select">@foreach ($registers as $r)<option value="{{ $r->id }}" @selected((int) old('cash_register_id') === $r->id)>{{ $r->code }} — {{ $r->name }} · {{ Fmt::money($cash[$r->id] ?? 0) }}</option>@endforeach</select></div>
            @if ($type !== 'cash_expense')
                <div><label class="label" for="e-emp">{{ __('İşçi (təhtəlhesab şəxs)') }}</label>
                    <select id="e-emp" name="employee_id" x-model="employee" class="select" required><option value="">—</option>
                        <template x-for="e in employees" :key="e.id"><option :value="e.id" x-text="e.name + (e.open ? ' · ' + fmt.number(e.open, 2) + ' ₼' : '')" :selected="e.id == employee"></option></template></select></div>
            @endif
            @if ($type === 'cash_expense')
                <div><label class="label" for="e-cat">{{ __('Xərc maddəsi') }}</label>
                    <select id="e-cat" name="expense_category_id" required class="select"><option value="">—</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected((int) old('expense_category_id') === $c->id)>{{ $c->name }}@unless ($c->affects_profit) ({{ __('mənfəətə təsir etmir') }})@endunless</option>@endforeach</select></div>
            @endif
            @if ($type !== 'advance_report')
                <div><label class="label" for="e-amount">{{ __('Məbləğ') }} (₼)</label><input id="e-amount" type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required class="input"></div>
            @endif
        </div>

        @if ($type === 'advance_report')
            <div class="text-sm" x-show="employee">{{ __('Açıq avans') }}: <strong x-text="fmt.number(open(), 2) + ' ₼'"></strong>
                · {{ __('xərc') }} <span x-text="fmt.number(spent(), 2)"></span> + {{ __('qaytarılır') }} <span x-text="fmt.number(+returned || 0, 2)"></span>
                <span x-show="spent() + (+returned || 0) > open() + 0.004" class="neg">— {{ __('avans qalığından çoxdur') }}</span></div>
            <div class="relative overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-muted"><tr><th class="py-1 text-left">{{ __('Xərc maddəsi') }}</th><th class="py-1 text-left">{{ __('Təsvir') }}</th><th class="py-1 text-right">{{ __('Məbləğ') }}</th><th></th></tr></thead>
                    <tbody>
                        <template x-for="(l, i) in lines" :key="i">
                            <tr>
                                <td class="py-1 pr-2"><select :name="`lines[${i}][expense_category_id]`" x-model="l.expense_category_id" class="select min-w-48" :aria-label="@js(__('Xərc maddəsi'))"><option value="">—</option>
                                    @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></td>
                                <td class="py-1 pr-2"><input :name="`lines[${i}][description]`" x-model="l.description" maxlength="255" class="input min-w-48" :aria-label="@js(__('Təsvir'))"></td>
                                <td class="py-1 pr-2"><input type="number" step="0.01" min="0" :name="`lines[${i}][amount]`" x-model="l.amount" class="input w-28 text-right" :aria-label="@js(__('Məbləğ'))"></td>
                                <td class="py-1"><button type="button" class="btn btn-sm h-8 px-2" @click="lines.splice(i, 1)" x-show="lines.length > 1" aria-label="{{ __('Sətri sil') }}"><x-icon name="trash" size="14"/></button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <button type="button" class="btn btn-sm" @click="lines.push({ expense_category_id: '', amount: '', description: '' })"><x-icon name="plus" size="14"/> {{ __('Xərc sətri') }}</button>
                <div class="sm:ml-auto"><label class="label" for="e-ret">{{ __('Kassaya qaytarılan') }} (₼)</label><input id="e-ret" type="number" step="0.01" min="0" name="returned" x-model="returned" class="input w-36"></div>
            </div>
        @endif
        <div><label class="label" for="e-note">{{ __('Qeyd') }}</label><input id="e-note" name="note" maxlength="1000" value="{{ old('note') }}" class="input"></div>
        <div class="flex justify-end"><button class="btn btn-primary">{{ __('Sənədi yarat') }}</button></div>
    </form>
</x-layouts.app>
