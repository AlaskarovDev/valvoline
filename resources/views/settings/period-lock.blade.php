@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <section class="card">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ __('Dövr bağlama') }}</h2>
                <div class="card-sub">{{ __('Hesabatı verilmiş dövrün rəqəmləri sonradan dəyişməsin: bağlanma tarixi (daxil olmaqla) və ondan əvvəlki günlərə sənəd yazılmır, dəyişdirilmir və ləğv edilmir.') }}</div>
            </div>
        </div>
        <div class="flex flex-col gap-4 p-4">
            <div @class(['card border-l-4 px-4 py-3 text-sm']) style="border-left-color: {{ $lockedUntil ? 'var(--warning)' : 'var(--positive)' }}">
                @if ($lockedUntil)
                    <div class="font-semibold">{{ __('Bağlıdır: :date daxil olmaqla', ['date' => Fmt::date($lockedUntil)]) }}</div>
                @else
                    <div class="font-semibold">{{ __('Dövr açıqdır — bütün tarixlərə sənəd yazmaq olar.') }}</div>
                @endif
                @if ($setting?->updated_at)
                    <div class="text-xs text-muted">{{ __('Son dəyişiklik') }}: {{ Fmt::date($setting->updated_at) }} · {{ $setting->user_name ?? '—' }}</div>
                @endif
            </div>
            <form method="POST" action="{{ route('period-lock.update') }}" class="flex flex-wrap items-end gap-2">
                @csrf @method('PUT')
                <div>
                    <label class="label" for="pl-date">{{ __('Bağlanma tarixi') }}</label>
                    <input id="pl-date" type="date" name="locked_until" class="input" max="{{ now()->toDateString() }}"
                           value="{{ $lockedUntil?->toDateString() ?? now()->subMonthNoOverflow()->endOfMonth()->toDateString() }}">
                </div>
                <button class="btn btn-primary">{{ __('Dövrü bağla') }}</button>
                @if ($lockedUntil)
                    <button name="unlock" value="1" class="btn" onclick="return confirm(@js(__('Dövr tam açılsın? Keçmiş tarixlərə yenidən sənəd yazmaq mümkün olacaq.')))">{{ __('Dövrü aç') }}</button>
                @endif
            </form>
            <ul class="list-disc pl-5 text-xs text-muted">
                <li>{{ __('Aid olan sənədlər: təhvil-təslim, satış fakturası, stok sənədləri, silinmə aktları, anbar transferləri, maliyyə sənədləri, bank əməliyyatları.') }}</li>
                <li>{{ __('Sifarişlər (rezerv) bağlanmır — onlar uçota düşmür.') }}</li>
                <li>{{ __('Excel importu admin tərəfindən aparılır və bağlamadan asılı deyil; hər import audit jurnalına yazılır.') }}</li>
                <li>{{ __('Bağlama və açılma audit jurnalında qeyd olunur.') }}</li>
            </ul>
        </div>
    </section>
</x-layouts.app>
