@php use App\Support\Fmt; $roles = ['rep' => __('Satış nümayəndəsi'), 'forwarder' => __('Ekspeditor')]; @endphp
<x-layouts.app :title="$title">
    <section class="card" x-data="{ open: false }">
        <div class="card-head">
            <div><h2 class="card-title">{{ __('Satış nümayəndələri və ekspeditorlar') }}</h2><div class="card-sub">{{ __('Hər nümayəndənin ayrıca kassası (kassa kodu) olur: onun aldığı müştəri ödənişləri həmin kassada oturur.') }}</div></div>
            <button type="button" class="btn btn-sm btn-primary" @click="open = !open"><x-icon name="plus" size="14"/> {{ __('Yeni nümayəndə') }}</button>
        </div>
        <form x-show="open" x-cloak method="POST" action="{{ route('reps.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
            @csrf
            @include('reps._fields', ['m' => null])
            <label class="flex items-center gap-2 text-sm"><input type="hidden" name="create_register" value="0"><input type="checkbox" name="create_register" value="1" checked class="size-4"> {{ __('Ayrıca kassa aç') }}</label>
            <div class="flex items-end justify-end lg:col-span-3"><button class="btn btn-primary">{{ __('Yadda saxla') }}</button></div>
        </form>
    </section>

    <section class="card overflow-hidden">
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr><th>{{ __('Kod') }}</th><th>{{ __('Ad') }}</th><th>{{ __('Rol') }}</th><th>{{ __('İş yeri') }}</th><th>{{ __('Kassa') }}</th><th class="num">{{ __('Kassa qalığı') }}</th><th>{{ __('Səyyar anbar') }}</th>
                <th class="num">{{ __('Müştəri') }}</th><th class="num">{{ __('Bu ay satış') }}</th><th class="num">{{ __('Plan') }}</th><th class="num">{{ __('Müştəri borcu') }}</th></tr></thead>
            <tbody>
                @foreach ($reps as $m)
                    <tr @class(['opacity-60' => ! $m->is_active])>
                        <td class="font-mono text-xs">{{ $m->code ?? '—' }}</td>
                        <td class="wrap font-medium">{{ $m->name }}@if ($m->phone)<div class="text-xs text-muted">{{ $m->phone }}</div>@endif</td>
                        <td>{{ $roles[$m->role] ?? $m->role }}</td>
                        <td>{{ $m->workplace_name ?? '—' }}</td>
                        <td>@if ($m->register_id)<a href="{{ route('finance.register', $m->register_id) }}" class="badge badge-primary">{{ $m->register_code }}</a>@else<span class="text-muted">—</span>@endif</td>
                        <td class="num">{{ $m->cash !== null ? Fmt::number($m->cash, 2) : '—' }}</td>
                        <td>{{ $m->warehouse_name ?? '—' }}</td>
                        <td class="num">{{ Fmt::number($m->customers) }}</td>
                        <td class="num">{{ Fmt::money($m->month_sales) }}</td>
                        <td class="num">{{ $m->plan !== null ? Fmt::pct($m->plan) : '—' }}</td>
                        <td class="num {{ $m->debt > 0 ? 'warn' : '' }}">{{ Fmt::money($m->debt) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    </section>

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Nümayəndə kartları') }}</h2></div>
        <div class="flex flex-col divide-y divide-[var(--border)]">
            @foreach ($reps as $m)
                <details class="px-4 py-2">
                    <summary class="cursor-pointer text-sm font-medium">{{ $m->code }} — {{ $m->name }}</summary>
                    <form method="POST" action="{{ route('reps.update', $m->id) }}" class="grid grid-cols-1 gap-3 py-3 sm:grid-cols-2 lg:grid-cols-4">
                        @csrf @method('PUT')
                        @include('reps._fields', ['m' => $m])
                        @unless ($m->register_id)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="create_register" value="1" class="size-4"> {{ __('Ayrıca kassa aç') }}</label>@endunless
                        <div class="flex items-end justify-end lg:col-span-3"><button class="btn btn-primary">{{ __('Yadda saxla') }}</button></div>
                    </form>
                </details>
            @endforeach
        </div>
    </section>
</x-layouts.app>
