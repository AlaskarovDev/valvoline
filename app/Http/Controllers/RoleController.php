<?php

namespace App\Http\Controllers;

use App\Support\AppSettings;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Tənzimləmələr → Rollar və icazələr: hər rolun hansı bölmələrə girişi var (admin həmişə hər şeyə).
 */
class RoleController extends Controller
{
    public function index(): View
    {
        return view('settings.roles', [
            'title' => __('Rollar və icazələr'), 'areas' => Permissions::areas(), 'roles' => Permissions::roles(), 'matrix' => Permissions::matrix(),
            'counts' => DB::table('users')->where('is_active', true)->groupBy('role')->selectRaw('role, COUNT(*) AS n')->pluck('n', 'role'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['perm' => ['nullable', 'array'], 'perm.*' => ['array'], 'perm.*.*' => ['string']]);
        $matrix = [];
        foreach (array_keys(Permissions::defaults()) as $role) {
            $matrix[$role] = array_values(array_intersect(array_keys(Permissions::areas()), $data['perm'][$role] ?? []));
        }
        AppSettings::set(Permissions::SETTING, json_encode($matrix), $request->user()->id);

        return redirect()->route('roles.index')->with('popup', ['title' => __('İcazələr yadda saxlanıldı'), 'message' => __('Dəyişiklik istifadəçilərin növbəti sorğusundan qüvvəyə minir.')]);
    }
}
