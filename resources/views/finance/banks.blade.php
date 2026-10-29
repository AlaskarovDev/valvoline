@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('finance.documents.create', ['type' => 'bank_transfer']) }}" class="btn btn-sm btn-primary"><x-icon name="refresh" size="14"/> {{ __('Banklar arası transfer') }}</a>
        <a href="{{ route('finance.documents.create', ['type' => 'bank_opening']) }}" class="btn btn-sm"><x-icon name="plus" size="14"/> {{ __('Bank açılışı') }}</a>
        <a href="{{ route('bank.index') }}" class="btn btn-sm">{{ __('Bank çıxarışları') }}</a>
        <div class="flex flex-wrap gap-2 sm:ml-auto">
            @foreach ($totals as $currency => $sum)<span class="badge badge-primary">{{ __('Cəmi') }} {{ $currency }}: {{ Fmt::number($sum, 2) }}</span>@endforeach
        </div>
    </div>

    @foreach ($banks as $bank)
        <section class="card overflow-hidden">
            <div class="card-head">
                <div><h2 class="card-title">{{ $bank->name }}</h2>
                    <div class="card-sub">@if ($bank->code){{ __('Kod') }} {{ $bank->code }} · @endif @if ($bank->swift)SWIFT {{ $bank->swift }} · @endif @if ($bank->voen){{ __('VÖEN') }} {{ $bank->voen }}@endif</div></div>
            </div>
            @include('finance._accounts', ['list' => $accounts[$bank->id] ?? collect()])
        </section>
    @endforeach
    @if (($accounts[0] ?? collect())->isNotEmpty())
        <section class="card overflow-hidden">
            <div class="card-head"><h2 class="card-title">{{ __('Banka bağlanmamış hesablar') }}</h2></div>
            @include('finance._accounts', ['list' => $accounts[0]])
        </section>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Yeni bank') }}</h2></div>
            <form method="POST" action="{{ route('finance.banks.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
                @csrf
                <div class="sm:col-span-2"><label class="label" for="bk-name">{{ __('Ad') }}</label><input id="bk-name" name="name" required class="input" placeholder="Kapital Bank"></div>
                <div><label class="label" for="bk-code">{{ __('Bank kodu') }}</label><input id="bk-code" name="code" class="input"></div>
                <div><label class="label" for="bk-swift">SWIFT</label><input id="bk-swift" name="swift" class="input font-mono"></div>
                <div><label class="label" for="bk-voen">{{ __('VÖEN') }}</label><input id="bk-voen" name="voen" class="input"></div>
                <div><label class="label" for="bk-corr">{{ __('Müxbir hesab') }}</label><input id="bk-corr" name="correspondent_account" class="input"></div>
                <div class="sm:col-span-2 flex justify-end"><button class="btn btn-primary">{{ __('Yadda saxla') }}</button></div>
            </form>
        </section>
        <section class="card">
            <div class="card-head"><div><h2 class="card-title">{{ __('Yeni bank hesabı') }}</h2><div class="card-sub">{{ __('Məs. Kapital Bank-da AZN, USD və RUB hesabları ayrıca açılır.') }}</div></div></div>
            <form method="POST" action="{{ route('finance.accounts.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2">
                @csrf
                <div><label class="label" for="ac-bank">{{ __('Bank') }}</label><select id="ac-bank" name="bank_id" class="select" required>@foreach ($banks as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
                <div><label class="label" for="ac-cur">{{ __('Valyuta') }}</label><select id="ac-cur" name="currency" class="select">@foreach (['AZN', 'USD', 'EUR', 'RUB', 'TRY', 'GBP'] as $c)<option>{{ $c }}</option>@endforeach</select></div>
                <div><label class="label" for="ac-iban">IBAN</label><input id="ac-iban" name="iban" class="input font-mono"></div>
                <div><label class="label" for="ac-name">{{ __('Hesabın adı') }}</label><input id="ac-name" name="name" class="input" placeholder="{{ __('avtomatik: bank + valyuta') }}"></div>
                <div class="sm:col-span-2 flex justify-end"><button class="btn btn-primary" @disabled($banks->isEmpty())>{{ __('Hesab aç') }}</button></div>
            </form>
        </section>
    </div>
</x-layouts.app>
