@php use App\Support\Fmt; $isAdmin = auth()->user()->can('finance.manage'); @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('finance.documents.create', ['type' => 'cash_receipt']) }}" class="btn btn-sm btn-primary"><x-icon name="plus" size="14"/> {{ __('Müştəri ödənişi (mədaxil)') }}</a>
        <a href="{{ route('finance.documents.create', ['type' => 'cash_transfer']) }}" class="btn btn-sm"><x-icon name="refresh" size="14"/> {{ __('Kassa transferi') }}</a>
        @if ($isAdmin)<a href="{{ route('finance.documents.create', ['type' => 'cash_opening']) }}" class="btn btn-sm"><x-icon name="plus" size="14"/> {{ __('Kassa açılışı') }}</a>@endif
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($registers as $r)
            <a href="{{ route('finance.register', $r->id) }}" class="card block p-4 hover:border-[var(--primary)]">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-mono text-xs text-muted">{{ $r->code }} · {{ $r->currency }}</div>
                        <div class="truncate font-semibold">{{ $r->name }}</div>
                        <div class="text-xs text-muted">{{ $r->type === 'main' ? __('Əsas kassa') : ($r->manager_name ?? '—') }}@if ($r->workplace_name) · {{ $r->workplace_name }}@endif</div>
                    </div>
                    @if (! $r->is_active)<span class="badge">{{ __('passiv') }}</span>@endif
                </div>
                <div class="mt-3 text-2xl font-semibold tabular-nums {{ $r->balance < 0 ? 'neg' : '' }}">{{ Fmt::number($r->balance, 2) }} <span class="text-sm text-muted">{{ $r->currency }}</span></div>
            </a>
        @endforeach
    </div>

    @if ($isAdmin)
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ __('Yeni kassa') }}</h2><div class="card-sub">{{ __('Satış nümayəndəsinə bağlı kassa: onun qəbul etdiyi müştəri ödənişləri bu kassada oturur.') }}</div></div></div>
            <form method="POST" action="{{ route('finance.registers.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6">
                @csrf
                <div class="sm:col-span-2"><label class="label" for="r-name">{{ __('Ad') }}</label><input id="r-name" name="name" required class="input"></div>
                <div><label class="label" for="r-code">{{ __('Kassa kodu') }}</label><input id="r-code" name="code" maxlength="16" class="input font-mono" placeholder="{{ __('avtomatik') }}"></div>
                <div><label class="label" for="r-mgr">{{ __('Satış nümayəndəsi') }}</label><select id="r-mgr" name="manager_id" class="select"><option value="">—</option>@foreach ($managers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
                <div><label class="label" for="r-wp">{{ __('İş yeri') }}</label><select id="r-wp" name="workplace_id" class="select" required>@foreach ($workplaces as $w)<option value="{{ $w->id }}">{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></div>
                <div><label class="label" for="r-cur">{{ __('Valyuta') }}</label><select id="r-cur" name="currency" class="select">@foreach (['AZN', 'USD', 'EUR', 'RUB'] as $c)<option>{{ $c }}</option>@endforeach</select></div>
                <div class="sm:col-span-2 lg:col-span-6 flex justify-end"><button class="btn btn-primary">{{ __('Yadda saxla') }}</button></div>
            </form>
        </section>
    @endif
</x-layouts.app>
