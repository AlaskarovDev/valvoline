<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * İlk admin istifadəçisi. Parol .env-dəki ADMIN_PASSWORD-dan, yoxdursa təsadüfi yaradılıb ekrana yazılır.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@valvo.az');
        if (User::query()->where('email', $email)->exists()) {
            return;
        }
        $password = env('ADMIN_PASSWORD') ?: Str::password(14, symbols: false);
        User::query()->create([
            'name' => 'Administrator',
            'email' => $email,
            'password' => $password,
            'role' => User::ROLE_ADMIN,
        ]);
        $this->command?->info("Admin: {$email} / {$password}");
    }
}
