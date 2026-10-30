@php use App\Support\Fmt; $c = $campaign; $isAdmin = auth()->user()->can('sales.manage'); @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('campaigns.index') }}" class="btn btn-sm"><x-icon name="back" size="14"/> {{ __('Geri') }}</a>
        <span @class(['badge', 'badge-primary' => $c->status === 'approved', 'badge-warn' => $c->status === 'pending'])>{{ $statuses[$c->status] ?? $c->status }}</span>
        @if (! $c->is_active)<span class="badge">{{ __('dayandırılıb') }}</span>@endif
        <span class="badge">{{ $kinds[$c->kind] ?? $c->kind }}</span>
        <span class="badge">{{ Fmt::date($c->begin_date) }} – {{ Fmt::date($c->end_date) }}</span>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @if ($isAdmin || ($c->created_by === auth()->id() && $c->status !== 'approved'))
                <a href="{{ route('campaigns.edit', $c) }}" class="btn btn-sm"><x-icon name="edit" size="14"/> {{ __('Düzəliş et') }}</a>
            @endif
            <a href="{{ route('campaigns.create', ['copy' => $c->id]) }}" class="btn btn-sm"><x-icon name="plus" size="14"/> {{ __('Kopyala') }}</a>
            @if ($isAdmin && $c->status === 'approved')
                <form method="POST" action="{{ route('campaigns.decide', $c) }}">@csrf
                    <input type="hidden" name="action" value="{{ $c->is_active ? 'deactivate' : 'activate' }}">
                    <button class="btn btn-sm">{{ $c->is_active ? __('Dayandır') : __('Aktivləşdir') }}</button>
                </form>
            @endif
            @if ($c->status === 'pending' && ($isAdmin || $c->created_by === auth()->id()))
                <form method="POST" action="{{ route('campaigns.decide', $c) }}" onsubmit="return confirm(@js(__('Kampaniya ləğv edilsin?')))">@csrf
                    <input type="hidden" name="action" value="cancel"><button class="btn btn-sm btn-danger">{{ __('Ləğv et') }}</button>
                </form>
            @endif
            @if ($isAdmin && ($usage['orders'] ?? 0) === 0)
                <form method="POST" action="{{ route('campaigns.destroy', $c) }}" onsubmit="return confirm(@js(__('Kampaniya silinsin?')))">@csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger"><x-icon name="trash" size="14"/> {{ __('Sil') }}</button>
                </form>
            @endif
        </div>
    </div>

    @if ($isAdmin && $c->status === 'pending')
        <section class="card border-l-4 p-4" style="border-left-color: var(--warning)" x-data="{ reject: false }">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm">{{ __('Kampaniya təsdiq gözləyir') }} · {{ $c->creator?->name }}</span>
                <form method="POST" action="{{ route('campaigns.decide', $c) }}" class="ml-auto">@csrf
                    <input type="hidden" name="action" value="approve"><button class="btn btn-sm btn-primary">{{ __('Təsdiqlə') }}</button>
                </form>
                <button type="button" class="btn btn-sm btn-danger" @click="reject = !reject">{{ __('Rədd et') }}</button>
            </div>
            <form x-show="reject" x-cloak method="POST" action="{{ route('campaigns.decide', $c) }}" class="mt-3 flex flex-wrap items-end gap-2">@csrf
                <input type="hidden" name="action" value="reject">
                <div class="min-w-0 flex-1"><label class="label" for="reject-reason">{{ __('Rədd səbəbi') }}</label><input id="reject-reason" name="reason" required class="input"></div>
                <button class="btn btn-danger">{{ __('Rədd et') }}</button>
            </form>
        </section>
    @endif
    @if ($c->status === 'rejected' && $c->rejection_reason)
        <div class="card border-l-4 px-4 py-3 text-sm" style="border-left-color: var(--negative)"><span class="label">{{ __('Rədd səbəbi') }}</span>{{ $c->rejection_reason }}</div>
    @endif

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-kpi :label="__('Sifariş sayı')" :value="Fmt::number($usage['orders'] ?? 0)"/>
        <x-kpi :label="__('Verilmiş endirim')" :value="Fmt::money($usage['discount'] ?? 0)"/>
        <x-kpi :label="__('Hədiyyə miqdarı')" :value="Fmt::qty($usage['gift_qty'] ?? 0)"/>
        <x-kpi :label="__('Təxmini büdcə')" :value="$c->estimated_budget !== null ? Fmt::money($c->estimated_budget) : '—'"
               :meta="$c->estimated_budget ? __('istifadə').' '.Fmt::pct((($usage['discount'] ?? 0)) / $c->estimated_budget) : null"/>
    </div>

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Şərtlər') }}</h2></div>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 p-4 text-sm md:grid-cols-[180px_minmax(0,1fr)]">
            <dt class="text-muted">{{ __('Şərt') }}</dt><dd>{{ $targets['condition'] }}</dd>
            @if ($c->isGift())<dt class="text-muted">{{ __('Hədiyyə malı') }}</dt><dd>{{ $c->giftProduct?->code }} — {{ $c->giftProduct?->name }}</dd>@endif
            <dt class="text-muted">{{ __('Mallar') }}</dt><dd>{{ $targets['products'] }}</dd>
            @if ($targets['excluded'])<dt class="text-muted">{{ __('Xaric mallar') }}</dt><dd>{{ $targets['excluded'] }}</dd>@endif
            <dt class="text-muted">{{ __('Müştərilər') }}</dt><dd>{{ $targets['customers'] }}</dd>
            @if ($targets['customer_excludes'])<dt class="text-muted">{{ __('Xaric müştərilər') }}</dt><dd>{{ $targets['customer_excludes'] }}</dd>@endif
            <dt class="text-muted">{{ __('Hesablama bazası') }}</dt><dd>{{ $c->formula_base === 'P1' ? __('Brüt məbləğ (P1)') : __('Əvvəlki endirimlərdən sonra (P3)') }}@if ($c->vat_multiplier != 1) · {{ __('ƏDV əmsalı') }} {{ Fmt::number($c->vat_multiplier, 2) }}@endif</dd>
            <dt class="text-muted">{{ __('Prioritet') }}</dt><dd>{{ $c->priority }}@if ($c->priority_group) · {{ __('eksklüziv qrup') }} «{{ $c->priority_group }}»@endif</dd>
            <dt class="text-muted">{{ __('Müştəri üzrə limit') }}</dt><dd>{{ $c->apply_limit > 0 ? __(':n sifariş', ['n' => $c->apply_limit]) : __('limitsiz') }}</dd>
            @if ($c->description)<dt class="text-muted">{{ __('Təsvir') }}</dt><dd class="whitespace-pre-line">{{ $c->description }}</dd>@endif
            <dt class="text-muted">{{ __('Yaradıb') }}</dt><dd>{{ $c->creator?->name ?? '—' }} · {{ Fmt::date($c->created_at) }}@if ($c->approver) · {{ __('təsdiqləyib') }}: {{ $c->approver->name }} ({{ Fmt::date($c->approved_at) }})@endif</dd>
        </dl>
    </section>

    <section class="card overflow-hidden">
        <div class="card-head"><h2 class="card-title">{{ __('Kampaniyanın tətbiq olunduğu sifarişlər') }}</h2></div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>{{ __('Tarix') }}</th><th>{{ __('Sifariş №') }}</th><th>{{ __('Müştəri') }}</th><th class="num">{{ __('Məbləğ') }}</th><th class="num">{{ __('Endirim') }}</th><th class="num">{{ __('Hədiyyə') }}</th></tr></thead>
                <tbody>
                    @forelse ($orders as $o)
                        <tr>
                            <td>{{ Fmt::date($o->date) }}</td>
                            <td><a href="{{ route('orders.show', $o->id) }}" class="text-primary-text hover:underline">{{ $o->order_no }}</a></td>
                            <td class="wrap">{{ $o->customer_name }}</td>
                            <td class="num">{{ Fmt::money($o->total_amount) }}</td>
                            <td class="num">{{ isset($discounts[$o->id]) ? Fmt::money($discounts[$o->id]) : '—' }}</td>
                            <td class="num">{{ isset($gifts[$o->id]) ? Fmt::qty($gifts[$o->id]->qty).' '.__('əd.') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">{{ __('Kampaniya hələ heç bir sifarişdə tətbiq olunmayıb.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
