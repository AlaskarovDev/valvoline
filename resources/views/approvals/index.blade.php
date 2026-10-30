@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <nav class="flex flex-wrap gap-2" aria-label="{{ __('Bölmələr') }}">
        @foreach (['pending' => __('Gözləyənlər'), 'approved' => __('Təsdiqlənənlər'), 'rejected' => __('Rədd edilənlər')] as $k => $l)
            <a href="{{ route('approvals.index', ['status' => $k]) }}" @class(['btn btn-sm', 'btn-primary' => $status === $k])>{{ $l }}</a>
        @endforeach
    </nav>
    @forelse ($rows as $r)
        <section class="card p-4">
            <div class="flex flex-wrap items-start gap-3">
                <div class="min-w-0 flex-1">
                    <a href="{{ route('orders.show', $r->subject_id) }}" class="font-semibold text-primary-text hover:underline">{{ __('Sifariş') }} № {{ $r->order_no }}</a>
                    <span class="text-sm text-muted">· {{ $r->customer_name }} · {{ Fmt::date($r->order_date) }} · {{ Fmt::money($r->amount) }}</span>
                    <ul class="mt-2 list-disc space-y-0.5 pl-5 text-sm">@foreach (json_decode($r->reasons, true) as $reason)<li class="warn">{{ $reason }}</li>@endforeach</ul>
                    <div class="mt-2 text-xs text-muted">{{ __('Göndərdi') }}: {{ $r->requester ?? '—' }} · {{ \Illuminate\Support\Carbon::parse($r->created_at)->format('d.m.Y H:i') }}
                        @if ($r->decider) · {{ $labels[$r->status] }}: {{ $r->decider }} · {{ \Illuminate\Support\Carbon::parse($r->decided_at)->format('d.m.Y H:i') }}@endif
                        @if ($r->decision_note) — {{ $r->decision_note }}@endif</div>
                </div>
                @if ($r->status === 'pending')
                    <form method="POST" action="{{ route('approvals.decide', $r->id) }}" class="flex w-full flex-wrap items-end gap-2 sm:w-auto">
                        @csrf
                        <div class="min-w-0 flex-1"><label class="label" for="n-{{ $r->id }}">{{ __('Qeyd') }}</label><input id="n-{{ $r->id }}" name="note" maxlength="500" class="input h-[34px] sm:w-56"></div>
                        <button name="decision" value="reject" class="btn btn-sm btn-danger h-[34px]">{{ __('Rədd et') }}</button>
                        <button name="decision" value="approve" class="btn btn-sm btn-primary h-[34px]">{{ __('Təsdiqlə') }}</button>
                    </form>
                @endif
            </div>
        </section>
    @empty
        <div class="card px-4 py-10 text-center text-sm text-muted">{{ __('Bu bölmədə sorğu yoxdur.') }}</div>
    @endforelse
</x-layouts.app>
