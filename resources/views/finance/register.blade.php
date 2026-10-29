@php use App\Support\Fmt; $r = $register; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('finance.registers') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span class="badge font-mono">{{ $r->code }}</span>
        @if ($r->manager_name)<span class="badge">{{ $r->manager_name }}</span>@endif
        @if ($r->workplace_name)<span class="badge">{{ __('İş yeri') }}: {{ $r->workplace_name }}</span>@endif
        <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('finance.documents.create', ['type' => 'cash_receipt', 'register' => $r->id]) }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Müştəri ödənişi') }}</a>
            <a href="{{ route('finance.documents.create', ['type' => 'cash_transfer', 'register' => $r->id]) }}" class="btn btn-sm"><x-icon name="refresh" size="14"/> {{ __('Transfer') }}</a>
            @if (auth()->user()->isAdmin())<a href="{{ route('finance.documents.create', ['type' => 'cash_opening', 'register' => $r->id]) }}" class="btn btn-sm">{{ __('Açılış') }}</a>@endif
        </div>
    </div>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Qalıq')" :value="Fmt::number($balance, 2).' '.$r->currency" :tone="$balance < 0 ? 'neg' : null" :big="true"/>
        <x-kpi :label="__('Mədaxil')" :value="Fmt::number($ledger->sum('in'), 2)"/>
        <x-kpi :label="__('Məxaric / transfer')" :value="Fmt::number($ledger->sum('out'), 2)"/>
        <x-kpi :label="__('Hərəkət sayı')" :value="Fmt::number($ledger->count())"/>
    </div>
    <section class="card overflow-hidden">
        <div class="card-head"><div><h2 class="card-title">{{ __('Kassa hərəkətləri') }}</h2><div class="card-sub">{{ __('Açılış sənədindən (yoxdursa kassanın yaradıldığı gündən) sonrakı hərəkətlər; import edilmiş nağd ödənişlər nümayəndənin kassasına düşür.') }}</div></div></div>
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Sənəd №') }}</th><th>{{ __('Təyinat') }}</th><th class="num">{{ __('Mədaxil') }}</th><th class="num">{{ __('Məxaric') }}</th><th class="num">{{ __('Qalıq') }}</th></tr></thead>
            <tbody>
                @forelse ($ledger->take(500) as $row)
                    <tr @class(['font-semibold' => $row->kind === 'opening'])>
                        <td>{{ Fmt::date($row->date) }}</td>
                        <td>@if ($row->doc_id)<a href="{{ route('finance.documents.show', $row->doc_id) }}" class="text-primary-text hover:underline">{{ $row->doc_no }}</a>@else<span class="text-muted">—</span>@endif</td>
                        <td class="wrap">{{ $row->text }}</td>
                        <td class="num pos">{{ $row->in ? Fmt::number($row->in, 2) : '' }}</td>
                        <td class="num neg">{{ $row->out ? Fmt::number($row->out, 2) : '' }}</td>
                        <td class="num">{{ Fmt::number($row->balance, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">{{ __('Hərəkət yoxdur. Mövcud qalığı «Açılış» sənədi ilə daxil edin.') }}</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>
</x-layouts.app>
