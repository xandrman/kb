<?php

namespace App\Models;

use App\Enums\AccessLevel;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Keycloak realm role mirrored locally; the administrator grants it an access level (FR-7).
 */
class Role extends SpatieRole
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_level' => AccessLevel::class,
        ];
    }
}
