<x-layouts.app :title="$title">
    @if (auth()->user()->can('stock.manage'))
        <section class="card" x-data="{ open: false, lines: [{ key: 1, product_id: '', qty: 1 }] }">
            <div class="card-head">
                <div><h2 class="card-title">{{ __('Yeni silinmə aktı') }}</h2><div class="card-sub">{{ __('Zədələnmiş, itmiş və ya vaxtı bitmiş mal. Vaxtı bitmiş partiyalar üçün «Son istifadə tarixləri» səhifəsindən istifadə edin.') }}</div></div>
                <button type="button" class="btn btn-sm btn-primary" @click="open = !open"><x-icon name="plus" size="14"/> {{ __('Akt yarat') }}</button>
            </div>
            <form x-show="open" x-cloak method="POST" action="{{ route('write-offs.store') }}" class="flex flex-col gap-3 p-4">
                @csrf
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-5">
                    <div><label class="label" for="w-date">{{ __('Tarix') }}</label><input id="w-date" type="date" name="date" required class="input" value="{{ now()->toDateString() }}"></div>
                    <div><label class="label" for="w-wh">{{ __('Anbar') }}</label><select id="w-wh" name="warehouse_id" class="select">@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
                    <div><label class="label" for="w-reason">{{ __('Səbəb') }}</label><select id="w-reason" name="reason" class="select">@foreach ($reasons as $k => $l)@continue($k === 'transfer_shortage')<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
<div><label class="label" for="w-wp">{{ __('İş yeri') }}</label>
                    <select id="w-wp" name="workplace_id" class="select" required>@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected((int) $workplaceId === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
                    <div><label class="label" for="w-note">{{ __('Qeyd') }}</label><input id="w-note" name="note" maxlength="500" class="input"></div>
                </div>
                <template x-for="(l, i) in lines" :key="l.key">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-0 flex-1"><label class="label" :for="'w-p' + i">{{ __('Mal') }}</label>
                            <select :id="'w-p' + i" class="select" :name="`lines[${i}][product_id]`" x-model="l.product_id" required>
                                <option value="">—</option>
                                @foreach ($products as $p)<option value="{{ $p['id'] }}">{{ $p['code'] }} — {{ $p['name'] }}</option>@endforeach
                            </select></div>
                        <div><label class="label" :for="'w-q' + i">{{ __('Miqdar') }}</label><input :id="'w-q' + i" type="number" step="any" min="0.0001" class="input w-28" :name="`lines[${i}][qty]`" x-model="l.qty" required></div>
                        <button type="button" class="btn btn-sm btn-danger h-9 px-2" @click="lines.length > 1 && lines.splice(i, 1)" aria-label="{{ __('Sətri sil') }}"><x-icon name="trash" size="14"/></button>
                    </div>
                </template>
                <div class="flex justify-between gap-2">
                    <button type="button" class="btn btn-sm" @click="lines.push({ key: Math.random(), product_id: '', qty: 1 })"><x-icon name="plus" size="14"/> {{ __('Mal əlavə et') }}</button>
                    <button class="btn btn-danger">{{ __('Silinmə aktı yarat') }}</button>
                </div>
            </form>
        </section>
    @endif
    <x-filters :filters="$filters" :own="['state']"><x-state-filter/></x-filters>
    <x-table :table="$table" :rows="$rows"/>
</x-layouts.app>
