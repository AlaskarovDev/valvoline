@php use App\Support\Fmt; @endphp
<x-layouts.app :title="$title">
    <section class="card overflow-hidden">
        <div class="card-head">
            <div>
                <h2 class="card-title">{{ __('Ehtiyat nüsxələri') }}</h2>
                <div class="card-sub">{{ __('Bütün baza gündə bir dəfə avtomatik nüsxələnir (son :n nüsxə saxlanılır). Fayllar serverdə vebdən bağlı qovluqdadır; yükləyib başqa yerdə də saxlayın.', ['n' => $keep]) }}</div>
            </div>
            <form method="POST" action="{{ route('backups.store') }}">@csrf<button class="btn btn-sm btn-primary"><x-icon name="download" size="14"/> {{ __('İndi yarat') }}</button></form>
        </div>
        @if ($backups)
            <div class="tbl-wrap relative">
                <table class="tbl">
                    <thead><tr><th>{{ __('Fayl') }}</th><th>{{ __('Vaxt') }}</th><th class="num">{{ __('Ölçü') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($backups as $b)
                            <tr>
                                <td class="font-mono text-xs">{{ $b['name'] }}</td>
                                <td>{{ $b['at']->format('d.m.Y H:i') }}</td>
                                <td class="num">{{ Fmt::number($b['size'] / 1024 / 1024, 2) }} MB</td>
                                <td class="text-right"><a href="{{ route('backups.download', $b['name']) }}" class="btn btn-sm"><x-icon name="download" size="14"/> {{ __('Yüklə') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="px-4 py-6 text-sm text-muted">{{ __('Hələ nüsxə yoxdur — «İndi yarat» düyməsini basın.') }}</p>
        @endif
        <p class="border-t border-line px-4 py-3 text-xs text-muted">{{ __('Bərpa: faylı açın (gunzip) və MySQL-ə yükləyin (phpMyAdmin → Import). Bərpa bütün mövcud məlumatı nüsxədəki ilə əvəz edir.') }}</p>
    </section>
</x-layouts.app>
