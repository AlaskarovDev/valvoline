<x-layouts.app :title="$title">
    <section class="card overflow-hidden">
        <div class="card-head"><div><h2 class="card-title">{{ __('İş yerləri') }}</h2><div class="card-sub">{{ __('Bütün sənədlərdə (sifariş, təhvil-təslim, faktura, transfer, silinmə, kassa və bank sənədləri) iş yeri göstərilir.') }}</div></div></div>
        <form method="POST" action="{{ route('workplaces.store') }}" class="flex flex-wrap items-end gap-2 border-b border-line px-4 py-3">
            @csrf
            <div><label class="label" for="wp-code">{{ __('Kod') }}</label><input id="wp-code" name="code" required maxlength="16" class="input h-[34px] w-28 font-mono" placeholder="IY-02"></div>
            <div class="min-w-0 flex-1 sm:flex-none"><label class="label" for="wp-name">{{ __('Ad') }}</label><input id="wp-name" name="name" required class="input h-[34px] sm:w-64"></div>
            <div class="min-w-0 flex-1 sm:flex-none"><label class="label" for="wp-addr">{{ __('Ünvan') }}</label><input id="wp-addr" name="address" class="input h-[34px] sm:w-64"></div>
            <button class="btn btn-sm btn-primary h-[34px]"><x-icon name="plus" size="14"/> {{ __('Əlavə et') }}</button>
        </form>
        <ul class="divide-y divide-[var(--border)]">
            @foreach ($workplaces as $w)
                <li class="px-4 py-2" x-data="{ edit: false }">
                    <div class="flex flex-wrap items-center gap-2 text-sm" x-show="!edit">
                        <span class="font-mono text-xs text-muted">{{ $w->code }}</span><span class="font-medium">{{ $w->name }}</span>
                        @if ($w->is_default)<span class="badge badge-primary">{{ __('Əsas') }}</span>@endif
                        @unless ($w->is_active)<span class="badge">{{ __('passiv') }}</span>@endunless
                        @if ($w->address)<span class="text-xs text-muted">· {{ $w->address }}</span>@endif
                        <span class="ml-auto text-xs text-muted">{{ __(':n sənəd', ['n' => $docs[$w->id] ?? 0]) }}</span>
                        <button type="button" class="btn btn-sm px-2" @click="edit = true" aria-label="{{ __('Düzəliş et') }}"><x-icon name="edit" size="14"/></button>
                    </div>
                    <form x-show="edit" x-cloak method="POST" action="{{ route('workplaces.update', $w) }}" class="flex flex-wrap items-center gap-2">
                        @csrf @method('PUT')
                        <input name="code" value="{{ $w->code }}" required class="input h-[30px] w-24 font-mono" aria-label="{{ __('Kod') }}">
                        <input name="name" value="{{ $w->name }}" required class="input h-[30px] w-56" aria-label="{{ __('Ad') }}">
                        <input name="address" value="{{ $w->address }}" class="input h-[30px] w-56" aria-label="{{ __('Ünvan') }}">
                        <label class="flex items-center gap-1 text-xs"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" class="size-4" @checked($w->is_default)> {{ __('Əsas') }}</label>
                        <label class="flex items-center gap-1 text-xs"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="size-4" @checked($w->is_active)> {{ __('Aktiv') }}</label>
                        <button class="btn btn-sm btn-primary">{{ __('Yadda saxla') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </section>

    <section class="card overflow-hidden">
        <div class="card-head"><div><h2 class="card-title">{{ __('İstifadəçilərin iş yeri') }}</h2><div class="card-sub">{{ __('Yeni sənəddə susmaya görə seçilən iş yeri.') }}</div></div></div>
        <form method="POST" action="{{ route('workplaces.assign') }}">
            @csrf @method('PUT')
            <div class="tbl-wrap"><table class="tbl">
                <thead><tr><th>{{ __('İstifadəçi') }}</th><th>{{ __('İş yeri') }}</th></tr></thead>
                <tbody>
                    @foreach ($users as $u)
                        <tr><td>{{ $u->name }} <span class="text-xs text-muted">{{ $u->email }}</span></td>
                            <td><select name="users[{{ $u->id }}]" class="select h-8 w-auto" aria-label="{{ __('İş yeri') }} {{ $u->name }}"><option value="">{{ __('Əsas iş yeri') }}</option>@foreach ($workplaces as $w)<option value="{{ $w->id }}" @selected($u->workplace_id === $w->id)>{{ $w->code }} — {{ $w->name }}</option>@endforeach</select></td></tr>
                    @endforeach
                </tbody>
            </table></div>
            <div class="flex justify-end border-t border-line p-4"><button class="btn btn-primary">{{ __('Yadda saxla') }}</button></div>
        </form>
    </section>
</x-layouts.app>
