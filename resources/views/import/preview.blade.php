@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="flex flex-wrap items-center gap-2">
        <span class="badge badge-primary">{{ $importer->fileHint() }} — {{ $importer->title() }}</span>
        @foreach ($preview['sheets'] as $sheet => $info)
            <span class="badge">{{ $sheet }}: {{ __(':n sətir', ['n' => Fmt::number($info['rows'])]) }}</span>
        @endforeach
        @if (! $preview['ok'])<span class="badge badge-neg">{{ __('Struktur xətası — import mümkün deyil') }}</span>@endif
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <section class="card xl:col-span-2">
            <div class="card-head"><h2 class="card-title">{{ __('Xətalar və xəbərdarlıqlar') }}</h2>
                <span class="badge {{ count($preview['errors']) ? 'badge-neg' : 'badge-pos' }}">{{ __(':n xəta', ['n' => count($preview['errors'])]) }}</span></div>
            <div class="max-h-72 overflow-y-auto p-4 text-sm">
                @if ($preview['errors'])
                    <p class="mb-2 text-xs text-muted">{{ __('Xətalı sətirlər import olunmayacaq (məs. tarix oxunmadı). Strukturu pozulmuş vərəq bütün importu dayandırır.') }}</p>
                    <ul class="space-y-1">
                        @foreach ($preview['errors'] as $e)<li><span class="badge">{{ $e['sheet'] }} #{{ $e['row'] }}</span> {{ $e['message'] }}</li>@endforeach
                    </ul>
                @else
                    <p class="pos">{{ __('Xəta yoxdur ✓') }}</p>
                @endif
                @if ($preview['pending'])
                    <h3 class="mt-4 mb-1 font-semibold warn">{{ __('«Gözləmədə» statusu ilə saxlanılacaq sətirlər') }}</h3>
                    <p class="mb-2 text-xs text-muted">{{ __('Bu sətirlər rədd edilmir; mal/müştəri əlavə olunandan və ya ad uyğunlaşdırılandan sonra avtomatik bağlanır.') }}</p>
                    <ul class="space-y-1">
                        @foreach (array_slice($preview['pending'], 0, 40, true) as $reason => $count)<li><span class="num badge badge-warn">{{ $count }}</span> {{ $reason }}</li>@endforeach
                    </ul>
                @endif
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Təsdiq') }}</h2></div>
            <form method="POST" action="{{ route('import.confirm', $batch) }}" class="space-y-3 p-4 text-sm" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                @if ($importer->isMasterData())
                    <p class="text-muted">{{ __('Kataloq faylı: mövcud qeydlər koda görə yenilənir, yeniləri əlavə olunur. Heç nə silinmir.') }}</p>
                @elseif ($previous->isNotEmpty())
                    <p>{{ __('Bu tip fayl artıq :times dəfə import edilib (:rows sətir). Nə edək?', ['times' => $previous->count(), 'rows' => $previous->sum('rows_total')]) }}</p>
                    <label class="flex items-start gap-2"><input type="radio" name="mode" value="replace" checked class="mt-1">
                        <span><b>{{ __('Köhnəni sil, yenisini yaz') }}</b> <span class="block text-xs text-muted">{{ __('Tövsiyə olunur — fayl tam dövrü əhatə edirsə') }}</span></span></label>
                    <label class="flex items-start gap-2"><input type="radio" name="mode" value="append" class="mt-1">
                        <span><b>{{ __('Əlavə et, dublikatları ötür') }}</b> <span class="block text-xs text-muted">{{ __('Satış və ödənişdə təbii açarla yoxlanılır; digər tiplərdə sətirlər sadəcə əlavə olunur') }}</span></span></label>
                @else
                    <input type="hidden" name="mode" value="replace">
                    <p class="text-muted">{{ __('Bu tip ilk dəfə import edilir.') }}</p>
                @endif
                <div class="flex gap-2 pt-2">
                    <button class="btn btn-primary" @disabled(! $preview['ok']) :disabled="busy">
                        <span x-show="!busy">{{ __('Təsdiq et və import et') }}</span><span x-show="busy" x-cloak>{{ __('Import edilir…') }}</span>
                    </button>
                </div>
            </form>
            <form method="POST" action="{{ route('import.cancel', $batch) }}" class="px-4 pb-4">
                @csrf
                <button class="btn btn-sm">{{ __('Ləğv et') }}</button>
            </form>
        </section>
    </div>

    @foreach ($preview['sheets'] as $sheet => $info)
        <section class="card overflow-hidden">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ $sheet }} — {{ __('ilk :n sətir', ['n' => count($info['sample'])]) }}</h2>
                    <div class="card-sub">{{ __(':n sətir', ['n' => Fmt::number($info['rows'])]) }} · {{ __(':n gözləmədə', ['n' => $info['pending']]) }} · {{ __(':n xəta', ['n' => $info['errors']]) }}
                        @if ($info['missing']) · <span class="neg">{{ __('çatışmayan sütunlar') }}: {{ implode(', ', $info['missing']) }}</span>@endif</div>
                </div>
            </div>
            <div class="tbl-wrap max-h-[480px]">
                <table class="tbl text-xs">
                    <thead><tr><th></th>@foreach ($info['headers'] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($info['sample'] as $row)
                        <tr @class(['row-neg' => $row['_status'] === 'error', 'row-warn' => $row['_status'] === 'pending'])>
                            <td>@if ($row['_status'] === 'error')<span class="neg">✕</span>@elseif ($row['_status'] === 'pending')<span class="warn">●</span>@else<span class="pos">✓</span>@endif</td>
                            @foreach ($info['headers'] as $h)
                                @php $v = $row[$h] ?? null; @endphp
                                <td @class(['num' => is_int($v) || is_float($v)])>{{ is_float($v) ? rtrim(rtrim(number_format($v, 4, ',', ' '), '0'), ',') : $v }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</x-layouts.app>
