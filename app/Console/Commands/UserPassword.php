<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * İstifadəçinin parolunu dəyişir (serverdə deploy agenti ilə də işlədilə bilər).
 */
class UserPassword extends Command
{
    protected $signature = 'user:password {email} {password}';

    protected $description = 'İstifadəçinin parolunu dəyişir';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();
        if ($user === null) {
            $this->error('İstifadəçi tapılmadı: '.$this->argument('email'));

            return self::FAILURE;
        }
        $user->forceFill(['password' => $this->argument('password')])->save();
        $this->info("{$user->email} üçün parol dəyişdirildi.");

        return self::SUCCESS;
    }
}
