<x-layouts.app :title="$title">
    <section class="card">
        <div class="card-head"><div><h2 class="card-title">{{ __('Yeni istifadəçi') }}</h2><div class="card-sub">{{ __('Satış nümayəndəsi (menecer) yalnız öz müştəri və satışlarını görür. Rolların icazələri «Rollar və icazələr» səhifəsindədir.') }} <a href="{{ route('roles.index') }}" class="text-primary-text hover:underline">{{ __('Rollar və icazələr') }}</a></div></div></div>
        <form method="POST" action="{{ route('users.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
            @csrf
            <div><label class="label" for="u-name">{{ __('Ad') }}</label><input id="u-name" name="name" value="{{ old('name') }}" required class="input"></div>
            <div><label class="label" for="u-email">{{ __('E-poçt') }}</label><input id="u-email" name="email" type="email" value="{{ old('email') }}" required class="input"></div>
            <div><label class="label" for="u-pass">Parol (min. 10)</label><input id="u-pass" name="password" type="password" required class="input" autocomplete="new-password"></div>
            <div><label class="label" for="u-role">{{ __('Rol') }}</label>
                <select id="u-role" name="role" class="select">@foreach (\App\Support\Permissions::roles() as $key => $label)<option value="{{ $key }}" @selected(old('role', 'menecer') === $key)>{{ $label }}</option>@endforeach</select></div>
            <div><label class="label" for="u-mgr">{{ __('Menecer (satışlardakı ad)') }}</label>
                <select id="u-mgr" name="manager_id" class="select"><option value="">—</option>
                    @foreach ($managers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
            <button class="btn btn-primary">{{ __('Yarat') }}</button>
        </form>
    </section>

    <section class="card overflow-hidden">
        <div class="card-head"><h2 class="card-title">{{ __('İstifadəçilər') }}</h2><span class="card-sub">{{ $users->count() }}</span></div>
        <ul class="divide-y divide-line">
            @foreach ($users as $u)
                <li class="grid grid-cols-1 gap-2 px-4 py-3 text-sm lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,3fr)] lg:items-center lg:gap-4">
                    <div class="font-medium">{{ $u->name }}</div>
                    <div class="truncate text-muted">{{ $u->email }}</div>
                    <form method="POST" action="{{ route('users.update', $u) }}" class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
                        @csrf @method('PUT')
                        <select name="role" class="select h-[34px] text-xs sm:w-44" aria-label="{{ __('Rol') }}">
                            @foreach (\App\Support\Permissions::roles() as $key => $label)<option value="{{ $key }}" @selected($u->role === $key)>{{ $label }}</option>@endforeach
                        </select>
                        <select name="is_active" class="select h-[34px] text-xs sm:w-24" aria-label="{{ __('Status') }}">
                            <option value="1" @selected($u->is_active)>{{ __('Aktiv') }}</option>
                            <option value="0" @selected(! $u->is_active)>{{ __('Bloklu') }}</option>
                        </select>
                        <select name="manager_id" class="select col-span-2 h-[34px] text-xs sm:w-44" aria-label="{{ __('Menecer') }}">
                            <option value="">{{ __('— menecer —') }}</option>
                            @foreach ($managers as $m)<option value="{{ $m->id }}" @selected($u->manager_id === $m->id)>{{ $m->name }}</option>@endforeach
                        </select>
                        <input name="password" type="password" placeholder="{{ __('yeni parol (ixtiyari)') }}" class="input h-[34px] text-xs sm:w-44" autocomplete="new-password" aria-label="{{ __('Yeni parol') }}">
                        <button class="btn btn-sm h-[34px]">{{ __('Yadda saxla') }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </section>
</x-layouts.app>
