@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="card px-4 py-3 text-sm text-muted">
        {{ __('«Mənfəətə təsir» bayrağı P&L və xərc hesabatlarını dərhal dəyişir. «Xeyr» olanlar (satış pulunun kassaya təhvili, bank köçürməsi, idxal mal ödənişi, avans…) yalnız Kassa hesabatında görünür.') }}
        {{ __('«Təsisçiyə ödəniş» tipi xərc sayılmır — P&L-də xalis mənfəətdən sonra ayrıca göstərilir.') }}
        {{ __('Əl ilə dəyişdirilən kateqoriya təkrar importda fayldakı bayraqla ezilmir.') }}
    </div>
    <section class="card overflow-hidden">
        <div class="hidden grid-cols-[minmax(0,1.4fr)_minmax(0,1.6fr)_90px_130px_130px_minmax(0,1.6fr)] gap-4 border-b border-line bg-surface-2 px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-muted lg:grid">
            <div>{{ __('Kateqoriya') }}</div><div>{{ __('İzah') }}</div><div class="text-right">{{ __('Sətir') }}</div><div class="text-right">{{ __('Mədaxil') }}</div><div class="text-right">{{ __('Məxaric') }}</div><div>{{ __('Tip · Mənfəətə təsir') }}</div>
        </div>
        <ul class="divide-y divide-line">
            @foreach ($categories as $c)
                <li class="grid grid-cols-1 gap-2 px-4 py-3 text-sm lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1.6fr)_90px_130px_130px_minmax(0,1.6fr)] lg:items-center lg:gap-4">
                    <div class="font-medium">{{ $c->name }} @if ($c->user_edited)<span class="badge badge-primary">{{ __('əl ilə') }}</span>@endif</div>
                    <div class="text-xs text-muted">{{ $c->description }}</div>
                    <div class="flex gap-4 text-xs tabular-nums lg:contents">
                        <div class="lg:text-right"><span class="text-muted lg:hidden">{{ __('Sətir:') }} </span>{{ Fmt::number($c->rows_count) }}</div>
                        <div class="lg:text-right"><span class="text-muted lg:hidden">{{ __('Mədaxil:') }} </span>{{ Fmt::money($c->income) }}</div>
                        <div class="lg:text-right"><span class="text-muted lg:hidden">{{ __('Məxaric:') }} </span>{{ Fmt::money($c->expense) }}</div>
                    </div>
                    <form method="POST" action="{{ route('categories.update', $c->id) }}" class="flex flex-wrap items-center gap-2">
                        @csrf @method('PUT')
                        <label class="sr-only" for="op-{{ $c->id }}">{{ __('Tip') }}</label>
                        <select id="op-{{ $c->id }}" name="op_type" class="select h-[34px] min-w-0 flex-1 text-xs lg:h-[30px]">
                            @foreach (array_unique(array_merge(['Xərc', 'Mədaxil', 'Təsisçiyə ödəniş', 'Köçürmə'], [$c->op_type])) as $op)
                                <option value="{{ $op }}" @selected($c->op_type === $op)>{{ __($op) }}</option>
                            @endforeach
                        </select>
                        <label class="sr-only" for="ap-{{ $c->id }}">{{ __('Mənfəətə təsir') }}</label>
                        <select id="ap-{{ $c->id }}" name="affects_profit" class="select h-[34px] w-24 text-xs lg:h-[30px]">
                            <option value="1" @selected($c->affects_profit)>{{ __('Bəli') }}</option>
                            <option value="0" @selected(! $c->affects_profit)>{{ __('Xeyr') }}</option>
                        </select>
                        <button class="btn btn-sm">{{ __('Saxla') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.app>
