<x-layouts.app :title="$title">
    <section class="card">
        <div class="card-head"><div><h2 class="card-title">Yeni istifadəçi</h2><div class="card-sub">Menecer rolu yalnız öz müştəri və satışlarını görür</div></div></div>
        <form method="POST" action="{{ route('users.store') }}" class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
            @csrf
            <div><label class="label" for="u-name">Ad</label><input id="u-name" name="name" value="{{ old('name') }}" required class="input"></div>
            <div><label class="label" for="u-email">E-poçt</label><input id="u-email" name="email" type="email" value="{{ old('email') }}" required class="input"></div>
            <div><label class="label" for="u-pass">Parol (min. 10)</label><input id="u-pass" name="password" type="password" required class="input" autocomplete="new-password"></div>
            <div><label class="label" for="u-role">Rol</label>
                <select id="u-role" name="role" class="select"><option value="menecer">Menecer</option><option value="admin">Admin</option></select></div>
            <div><label class="label" for="u-mgr">Menecer (satışlardakı ad)</label>
                <select id="u-mgr" name="manager_id" class="select"><option value="">—</option>
                    @foreach ($managers as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
            <button class="btn btn-primary">Yarat</button>
        </form>
    </section>

    <section class="card overflow-hidden">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Ad</th><th>E-poçt</th><th>Rol · menecer · status · yeni parol</th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td class="font-medium">{{ $u->name }}</td>
                        <td>{{ $u->email }}</td>
                        <td>
                            <form method="POST" action="{{ route('users.update', $u) }}" class="flex flex-wrap items-center gap-2">
                                @csrf @method('PUT')
                                <select name="role" class="select h-[30px] w-28 text-xs" aria-label="Rol">
                                    <option value="admin" @selected($u->role === 'admin')>Admin</option>
                                    <option value="menecer" @selected($u->role === 'menecer')>Menecer</option>
                                </select>
                                <select name="manager_id" class="select h-[30px] w-44 text-xs" aria-label="Menecer">
                                    <option value="">—</option>
                                    @foreach ($managers as $m)<option value="{{ $m->id }}" @selected($u->manager_id === $m->id)>{{ $m->name }}</option>@endforeach
                                </select>
                                <select name="is_active" class="select h-[30px] w-24 text-xs" aria-label="Status">
                                    <option value="1" @selected($u->is_active)>Aktiv</option>
                                    <option value="0" @selected(! $u->is_active)>Bloklu</option>
                                </select>
                                <input name="password" type="password" placeholder="yeni parol (ixtiyari)" class="input h-[30px] w-44 text-xs" autocomplete="new-password" aria-label="Yeni parol">
                                <button class="btn btn-sm">Yadda saxla</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-layouts.app>
