<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('finance.documents') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        @if ($doc->register)<a href="{{ route('finance.register', $doc->register) }}" class="badge badge-primary">{{ $doc->register->code }}</a>@endif
        @if ($doc->toRegister)<a href="{{ route('finance.register', $doc->toRegister) }}" class="badge badge-primary">{{ $doc->toRegister->code }}</a>@endif
        <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
            <a href="{{ route('finance.documents.show', [$doc, 'print' => 1]) }}" target="_blank" rel="noopener" class="btn btn-sm btn-primary"><x-icon name="print" size="14"/> {{ __('Çap et') }}</a>
            @if (auth()->user()->isAdmin())
                <form method="POST" action="{{ route('finance.documents.destroy', $doc) }}" onsubmit="return confirm(@js(__('Sənəd ləğv edilsin?')))">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">{{ __('Ləğv et') }}</button></form>
            @endif
        </div>
    </div>
    <section class="card p-4">@include('finance._details')</section>
</x-layouts.app>
