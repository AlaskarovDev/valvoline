<?php

namespace App\Http\Controllers;

use App\Models\Manager;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('users.index', [
            'title' => 'İstifadəçilər',
            'users' => User::query()->with('manager')->orderBy('name')->get(),
            'managers' => Manager::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::min(10)],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_MANAGER])],
            'manager_id' => ['nullable', 'required_if:role,'.User::ROLE_MANAGER, 'exists:managers,id'],
        ]);
        User::query()->create($data + ['is_active' => true]);

        return back()->with('status', "İstifadəçi yaradıldı: {$data['email']}");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_MANAGER])],
            'manager_id' => ['nullable', 'required_if:role,'.User::ROLE_MANAGER, 'exists:managers,id'],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', Password::min(10)],
        ]);
        if ($user->is($request->user()) && ($data['role'] !== User::ROLE_ADMIN || ! $data['is_active'])) {
            return back()->with('error', 'Öz admin hüququnuzu ləğv edə bilməzsiniz.');
        }
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $user->fill($data)->save();

        return back()->with('status', "{$user->email} yeniləndi.");
    }
}
