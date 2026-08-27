<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// Central model — always lives in the `public` schema, regardless of which
// tenant schema is currently active on the default connection (see
// PostgreSQLSchemaManager: tenant search_path does not include `public`).
//
// `is_landlord`/`landlord_granted_by`/`landlord_granted_at` are
// deliberately NOT in the Fillable list below — this is a platform-wide
// authority flag (see app/Http/Middleware/EnsureLandlord.php) and must
// never be settable via ordinary mass-assignment (registration, profile
// updates, etc.). Grant it only via direct property assignment + save()
// in a dedicated, explicitly-audited admin action.
#[Connection('pgsql')]
#[Fillable(['name', 'username', 'email', 'status', 'password', 'locale'])]
#[Hidden(['password', 'remember_token'])]
#[RouteKey('uuid')]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuid, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'landlord_granted_at' => 'datetime',
            'is_landlord' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
