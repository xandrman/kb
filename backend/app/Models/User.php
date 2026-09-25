<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessLevel;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'keycloak_id', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * The strictest access level the user's roles open; public documents are open to everyone (FR-7).
     */
    public function clearance(): AccessLevel
    {
        return $this->roles
            ->pluck('access_level')
            ->filter(fn (mixed $level): bool => $level instanceof AccessLevel)
            ->sortBy(fn (AccessLevel $level): int => $level->rank())
            ->last() ?? AccessLevel::Public;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole('kb-admin');
    }
}
