@php use App\Support\Fmt; $d = $doc; @endphp
<dl class="grid grid-cols-[170px_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
    <dt class="muted text-muted">{{ __('Sənəd növü') }}</dt><dd>{{ $types[$d->type] }}</dd>
    <dt class="muted text-muted">{{ __('Tarix') }}</dt><dd>{{ Fmt::date($d->date) }}</dd>
    <dt class="muted text-muted">{{ __('İş yeri') }}</dt><dd>{{ $d->workplace->code }} — {{ $d->workplace->name }}</dd>
    @if ($d->register)<dt class="muted text-muted">{{ $d->type === 'cash_transfer' ? __('Göndərən kassa') : __('Kassa') }}</dt><dd>{{ $d->register->code }} — {{ $d->register->name }}</dd>@endif
    @if ($d->toRegister)<dt class="muted text-muted">{{ __('Qəbul edən kassa') }}</dt><dd>{{ $d->toRegister->code }} — {{ $d->toRegister->name }}</dd>@endif
    @if ($d->account)<dt class="muted text-muted">{{ $d->type === 'bank_transfer' ? __('Göndərən hesab') : __('Bank hesabı') }}</dt><dd>{{ $d->account->bankRef?->name ? $d->account->bankRef->name.' · ' : '' }}{{ $d->account->name }}@if ($d->account->iban) · {{ $d->account->iban }}@endif</dd>@endif
    @if ($d->toAccount)<dt class="muted text-muted">{{ __('Qəbul edən hesab') }}</dt><dd>{{ $d->toAccount->bankRef?->name ? $d->toAccount->bankRef->name.' · ' : '' }}{{ $d->toAccount->name }}@if ($d->toAccount->iban) · {{ $d->toAccount->iban }}@endif</dd>@endif
    @if ($d->customer)<dt class="muted text-muted">{{ __('Müştəri') }}</dt><dd>{{ $d->customer->name }} · {{ $d->customer->code }}</dd>@endif
    @if ($d->supplier)<dt class="muted text-muted">{{ __('Təchizatçı') }}</dt><dd>{{ $d->supplier->name }} · {{ $d->supplier->code }}</dd>
        @if ($d->supplier->currency !== $d->currency)<dt class="muted text-muted">{{ __('Borcdan silinən məbləğ') }}</dt><dd>{{ Fmt::number($d->to_amount, 2) }} {{ $d->supplier->currency }}</dd>@endif
    @endif
    @if ($d->manager)<dt class="muted text-muted">{{ __('Satış nümayəndəsi') }}</dt><dd>{{ $d->manager->name }}</dd>@endif
    <dt class="muted text-muted">{{ in_array($d->type, ['cash_opening', 'bank_opening'], true) ? __('İlkin qalıq') : __('Məbləğ') }}</dt><dd class="text-lg font-semibold">{{ Fmt::number($d->amount, 2) }} {{ $d->currency }}</dd>
    @if ($d->type === 'bank_transfer' && $d->to_amount && $d->toAccount && $d->toAccount->currency !== $d->currency)
        <dt class="muted text-muted">{{ __('Qəbul edilən məbləğ') }}</dt><dd>{{ Fmt::number($d->to_amount, 2) }} {{ $d->toAccount->currency }} · {{ __('məzənnə') }} {{ Fmt::number($d->rate, 4) }}</dd>
    @endif
    @if ($d->note)<dt class="muted text-muted">{{ __('Qeyd') }}</dt><dd>{{ $d->note }}</dd>@endif
    <dt class="muted text-muted">{{ __('Yaradıb') }}</dt><dd>{{ $d->creator?->name ?? '—' }} · {{ $d->created_at?->format('d.m.Y H:i') }}</dd>
</dl>
