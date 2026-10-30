@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <section class="card">
        <div class="card-head"><div><h2 class="card-title">{{ __('Yeni sayım') }}</h2>
            <div class="card-sub">{{ __('Sayım qaralama kimi açılır: telefonla və ya skanerlə barkod oxudulur, bir neçə nəfər eyni anda saya bilər. Tamamlananda fərqlər üçün sayım artığı / əskiyi sənədləri avtomatik yaranır.') }}</div></div></div>
        <form method="POST" action="{{ route('stock-counts.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
            @csrf
            <div><label class="label" for="c-wh">{{ __('Anbar') }}</label><select id="c-wh" name="warehouse_id" class="select">@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></div>
            <div><label class="label" for="c-date">{{ __('Tarix') }}</label><input id="c-date" type="date" name="date" value="{{ now()->toDateString() }}" max="{{ now()->toDateString() }}" class="input"></div>
            <div><label class="label" for="c-scope">{{ __('Növ') }}</label><select id="c-scope" name="scope" class="select">
                <option value="partial">{{ __('Seçmə sayım — yalnız sayılan mallar') }}</option><option value="full">{{ __('Tam sayım — sayılmayan mal 0 sayılır') }}</option></select></div>
            <div><label class="label" for="c-note">{{ __('Qeyd') }}</label><input id="c-note" name="note" maxlength="1000" class="input"></div>
            <button class="btn btn-primary">{{ __('Sayımı başla') }}</button>
        </form>
    </section>
    <section class="card overflow-hidden">
        <div class="tbl-wrap relative">
            <table class="tbl">
                <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Sənəd №') }}</th><th>{{ __('Anbar') }}</th><th>{{ __('Növ') }}</th><th class="num">{{ __('Sayılan mal') }}</th><th>{{ __('Vəziyyət') }}</th><th>{{ __('Yaradıb') }}</th></tr></thead>
                <tbody>
                    @forelse ($counts as $c)
                        <tr>
                            <td>{{ Fmt::date($c->date) }}</td>
                            <td><a href="{{ route('stock-counts.show', $c) }}" class="text-primary-text hover:underline">{{ $c->doc_no }}</a></td>
                            <td>{{ $c->warehouse->name }}</td>
                            <td>{{ $c->scope === 'full' ? __('Tam') : __('Seçmə') }}</td>
                            <td class="num">{{ (int) ($lines[$c->id] ?? 0) }}</td>
                            <td>@if ($c->cancelled_at)<span class="badge badge-neg">{{ __('Ləğv edilib') }}</span>@elseif ($c->completed_at)<span class="badge badge-pos">{{ __('Tamamlanıb') }}</span>@else<span class="badge badge-warn">{{ __('Davam edir') }}</span>@endif</td>
                            <td class="text-muted">{{ $c->creator?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-muted">{{ __('Sayım yoxdur.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
