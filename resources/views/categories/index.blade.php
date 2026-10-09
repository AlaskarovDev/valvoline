@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="card px-4 py-3 text-sm text-muted">
        «Mənfəətə təsir» bayrağı P&amp;L və xərc hesabatlarını <b>dərhal</b> dəyişir. «Xeyr» olanlar (satış pulunun kassaya təhvili, bank köçürməsi, idxal mal ödənişi, avans…) yalnız Kassa hesabatında görünür.
        Əl ilə dəyişdirilən kateqoriya təkrar importda fayldakı bayraqla ezilmir.
    </div>
    <section class="card overflow-hidden">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Kateqoriya</th><th>İzah</th><th class="num">Sətir</th><th class="num">Mədaxil</th><th class="num">Məxaric</th><th>Tip · Mənfəətə təsir</th></tr></thead>
                <tbody>
                @foreach ($categories as $c)
                    <tr>
                        <td class="font-medium">{{ $c->name }} @if ($c->user_edited)<span class="badge badge-primary">əl ilə</span>@endif</td>
                        <td class="max-w-80 text-xs text-muted">{{ $c->description }}</td>
                        <td class="num">{{ Fmt::number($c->rows_count) }}</td>
                        <td class="num">{{ Fmt::money($c->income) }}</td>
                        <td class="num">{{ Fmt::money($c->expense) }}</td>
                        <td>
                            <form method="POST" action="{{ route('categories.update', $c->id) }}" class="flex flex-wrap items-center gap-2">
                                @csrf @method('PUT')
                                <label class="sr-only" for="op-{{ $c->id }}">Tip</label>
                                <select id="op-{{ $c->id }}" name="op_type" class="select h-[30px] w-32 text-xs">
                                    @foreach (array_unique(array_merge(['Xərc', 'Mədaxil', 'Köçürmə'], [$c->op_type])) as $op)
                                        <option value="{{ $op }}" @selected($c->op_type === $op)>{{ $op }}</option>
                                    @endforeach
                                </select>
                                <label class="sr-only" for="ap-{{ $c->id }}">Mənfəətə təsir</label>
                                <select id="ap-{{ $c->id }}" name="affects_profit" class="select h-[30px] w-24 text-xs">
                                    <option value="1" @selected($c->affects_profit)>Bəli</option>
                                    <option value="0" @selected(! $c->affects_profit)>Xeyr</option>
                                </select>
                                <button class="btn btn-sm">Yadda saxla</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
