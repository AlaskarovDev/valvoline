@php use App\Support\Fmt; @endphp
<div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>{{ __('Hesab') }}</th><th>{{ __('Valyuta') }}</th><th>IBAN</th><th>{{ __('Açılış') }}</th><th>{{ __('Son əməliyyat') }}</th><th class="num">{{ __('Qalıq') }}</th><th></th></tr></thead>
    <tbody>
        @forelse ($list as $a)
            <tr @class(['opacity-60' => ! $a->is_active])>
                <td class="wrap"><a href="{{ route('bank.show', ['account' => $a->id]) }}" class="text-primary-text hover:underline">{{ $a->name }}</a></td>
                <td>{{ $a->currency }}</td>
                <td class="font-mono text-xs">{{ $a->iban ?? '—' }}</td>
                <td>{{ $a->opening ? Fmt::date($a->opening->date).' · '.Fmt::number($a->opening->amount, 2) : '—' }}</td>
                <td>{{ Fmt::date($a->last_date) }}</td>
                <td class="num font-semibold {{ $a->balance < 0 ? 'neg' : '' }}">{{ Fmt::number($a->balance, 2) }}</td>
                <td class="whitespace-nowrap">
                    <a href="{{ route('finance.documents.create', ['type' => 'bank_transfer', 'account' => $a->id]) }}" class="btn btn-sm">{{ __('Transfer') }}</a>
                    <a href="{{ route('finance.documents.create', ['type' => 'bank_opening', 'account' => $a->id]) }}" class="btn btn-sm">{{ __('Açılış') }}</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted">{{ __('Hesab yoxdur.') }}</td></tr>
        @endforelse
    </tbody>
</table></div>
