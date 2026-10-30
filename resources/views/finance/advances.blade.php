@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('cash-expenses.create', ['type' => 'cash_advance']) }}" class="btn btn-sm"><x-icon name="plus" size="14"/> {{ __('Avans ver') }}</a>
        <a href="{{ route('cash-expenses.create', ['type' => 'advance_report']) }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Avans hesabatı') }}</a>
    </div>
    <section class="card overflow-hidden">
        <div class="tbl-wrap relative">
            <table class="tbl">
                <thead><tr><th>{{ __('İşçi') }}</th><th class="num">{{ __('Verilən avans') }}</th><th class="num">{{ __('Hesabat verilib') }}</th><th class="num">{{ __('Açıq qalıq') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr>
                            <td>{{ $r->name }}</td>
                            <td class="num">{{ Fmt::money($r->given) }}</td>
                            <td class="num">{{ Fmt::money($r->reported) }}</td>
                            <td @class(['num font-semibold', 'warn' => $r->open > 0.005, 'pos' => $r->open <= 0.005])>{{ Fmt::money($r->open) }}</td>
                            <td class="text-right">@if ($r->open > 0.005)<a href="{{ route('cash-expenses.create', ['type' => 'advance_report', 'employee' => $r->id]) }}" class="text-primary-text hover:underline">{{ __('Hesabat') }}</a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-muted">{{ __('Verilmiş avans yoxdur.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
