<x-layouts.app :title="$title">
    <form method="POST" action="{{ route('roles.update') }}" class="flex flex-col gap-4">
        @csrf @method('PUT')
        <section class="card overflow-hidden">
            <div class="card-head">
                <div>
                    <h2 class="card-title">{{ __('Rollar və icazələr') }}</h2>
                    <div class="card-sub">{{ __('Hər rol hansı bölmələri görür və idarə edir. Admin həmişə hər şeyə icazəlidir; satış nümayəndəsi əlavə olaraq yalnız öz müştərilərinin məlumatını görür.') }}</div>
                </div>
                <button class="btn btn-sm btn-primary">{{ __('Yadda saxla') }}</button>
            </div>
            <div class="tbl-wrap relative">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>{{ __('Bölmə') }}</th>
                            @foreach ($roles as $key => $label)
                                <th class="text-center">{{ $label }}<div class="text-[11px] font-normal normal-case text-muted">{{ __(':n istifadəçi', ['n' => $counts[$key] ?? 0]) }}</div></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($areas as $area => [$label, $help])
                            <tr>
                                <td class="wrap"><div class="font-medium">{{ $label }}</div><div class="text-xs text-muted">{{ $help }}</div></td>
                                @foreach ($roles as $role => $roleLabel)
                                    <td class="text-center">
                                        @if ($role === 'admin')
                                            <span class="pos" aria-label="{{ __('icazə var') }}">✓</span>
                                        @else
                                            <input type="checkbox" name="perm[{{ $role }}][]" value="{{ $area }}" class="size-4" aria-label="{{ $roleLabel }} — {{ $label }}" @checked(in_array($area, $matrix[$role] ?? [], true))>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </form>
</x-layouts.app>
