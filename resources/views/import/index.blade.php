@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <section class="card xl:col-span-2">
            <div class="card-head">
                <div>
                    <h2 class="card-title">Fayl yüklə</h2>
                    <div class="card-sub">Ardıcıllıq vacibdir: əvvəl mallar və müştərilər, sonra əməliyyat faylları. Növbəti addımda önizləmə göstəriləcək.</div>
                </div>
            </div>
            <form method="POST" action="{{ route('import.upload') }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-[1fr_220px_auto] sm:items-end">
                @csrf
                <div>
                    <label class="label" for="file">Excel faylı (.xlsx)</label>
                    <input id="file" name="file" type="file" accept=".xlsx" required class="input pt-1.5">
                </div>
                <div>
                    <label class="label" for="type">Fayl tipi</label>
                    <select id="type" name="type" class="select">
                        <option value="">Fayl adından təyin et</option>
                        @foreach ($importers as $type => $importer)
                            <option value="{{ $type }}">{{ $importer->fileHint() }} — {{ $importer->title() }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary"><x-icon name="import" size="16"/> Önizləmə</button>
            </form>
        </section>
        <livewire:rebuild-status/>
    </div>

    <section class="card overflow-hidden">
        <div class="card-head"><h2 class="card-title">Fayl tipləri və son import</h2></div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>#</th><th>Fayl</th><th>Vərəqlər</th><th>Son import</th><th class="num">Sətir</th><th class="num">Gözləmədə</th><th class="num">Xəta</th></tr></thead>
                <tbody>
                @foreach ($importers as $type => $importer)
                    @php $b = $last[$type]; @endphp
                    <tr>
                        <td class="text-muted">{{ $loop->iteration }}</td>
                        <td><span class="font-medium">{{ $importer->fileHint() }}</span><div class="text-xs text-muted">{{ $importer->title() }}</div></td>
                        <td class="text-xs text-muted">{{ implode(', ', array_keys($importer->sheets())) }}</td>
                        <td>@if ($b){{ Fmt::date($b->imported_at) }} {{ $b->imported_at->format('H:i') }}<div class="text-xs text-muted">{{ $b->file_name }}</div>@else<span class="badge badge-warn">import edilməyib</span>@endif</td>
                        <td class="num">{{ $b ? Fmt::number($b->rows_total) : '—' }}</td>
                        <td class="num {{ $b && $b->rows_pending ? 'warn' : '' }}">{{ $b ? Fmt::number($b->rows_pending) : '—' }}</td>
                        <td class="num {{ $b && $b->rows_error ? 'neg' : '' }}">{{ $b ? Fmt::number($b->rows_error) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="card overflow-hidden">
        <div class="card-head"><h2 class="card-title">Import tarixçəsi</h2><span class="card-sub">Geri qaytarma həmin importun bütün sətirlərini silir və hesablamaları yeniləyir</span></div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Tarix</th><th>Fayl</th><th>Tip</th><th>İstifadəçi</th><th class="num">Sətir</th><th class="num">Gözləmədə</th><th class="num">Xəta</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse ($history as $b)
                    <tr>
                        <td class="whitespace-nowrap">{{ $b->created_at->format('d.m.Y H:i') }}</td>
                        <td>{{ $b->file_name }}</td>
                        <td>{{ isset($importers[$b->file_type]) ? $importers[$b->file_type]->title() : $b->file_type }}</td>
                        <td>{{ $b->user?->name ?? 'konsol' }}</td>
                        <td class="num">{{ Fmt::number($b->rows_total) }}</td>
                        <td class="num">{{ Fmt::number($b->rows_pending) }}</td>
                        <td class="num">
                            @if ($b->rows_error)
                                <details class="text-left">
                                    <summary class="neg cursor-pointer">{{ $b->rows_error }}</summary>
                                    <ul class="mt-1 max-h-48 overflow-y-auto text-xs text-muted">
                                        @foreach (array_slice($b->errors ?? [], 0, 50) as $e)<li>{{ $e['sheet'] }} #{{ $e['row'] }}: {{ $e['message'] }}</li>@endforeach
                                    </ul>
                                </details>
                            @else 0 @endif
                        </td>
                        <td>
                            @switch($b->status)
                                @case('done')<span class="badge badge-pos">aktiv</span>@break
                                @case('replaced')<span class="badge">əvəzlənib</span>@break
                                @case('rolled_back')<span class="badge badge-warn">geri qaytarılıb</span>@break
                                @default<span class="badge badge-neg">{{ $b->status }}</span>
                            @endswitch
                        </td>
                        <td class="text-right">
                            @if ($b->status === 'done')
                                <form method="POST" action="{{ route('import.rollback', $b) }}" onsubmit="return confirm('Bu importun bütün sətirləri silinsin?')">
                                    @csrf
                                    <button class="btn btn-sm btn-danger">Geri qaytar</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-8 text-center text-muted">Hələ import yoxdur</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
