<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'manager_id', 'workplace_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'menecer';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Rolun bölməyə icazəsi (Tənzimləmələr → Rollar və icazələr). Admin həmişə icazəlidir.
     */
    public function hasPermission(string $area): bool
    {
        return Permissions::allows($this->role, $area);
    }

    public function roleLabel(): string
    {
        return Permissions::roles()[$this->role] ?? $this->role;
    }

    /**
     * Satış nümayəndəsi (menecer) yalnız öz menecer_id-sinin məlumatını görür.
     * Digər rollar üçün null qaytarır (icazəli bölmələrdə bütün məlumat).
     */
    public function restrictedManagerId(): ?int
    {
        if ($this->role !== self::ROLE_MANAGER) {
            return null;
        }

        return $this->manager_id ?? -1;
    }
}
