<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Single-row-per-tenant notification settings — per-channel on/off toggles
 * plus tenant-supplied Twilio credentials for the (not-yet-built) bulk
 * WhatsApp/SMS paths. See
 * .ai/skills/cncms/cncms-context/references/bill-notifications.md section 3
 * for why this is its own table rather than living on Company (different
 * sensitivity/lifecycle than branding/contact fields).
 *
 * The Twilio fields are genuinely sensitive API credentials, never stored
 * plaintext — see casts() below.
 */
#[Fillable(['whatsapp_enabled', 'email_enabled', 'sms_enabled', 'twilio_account_sid', 'twilio_auth_token', 'twilio_whatsapp_number'])]
#[RouteKey('uuid')]
class NotificationSetting extends Model
{
    use Auditable, HasUuid;

    protected function casts(): array
    {
        return [
            'whatsapp_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            // Laravel's built-in 'encrypted' cast: encrypted on write,
            // transparently decrypted on read via the model attribute
            // accessor. The raw database column never holds plaintext —
            // verified directly against the DB in
            // tests/Feature/Web/SettingsNotificationsTest.php rather than
            // just trusting the cast decrypts correctly.
            'twilio_account_sid' => 'encrypted',
            'twilio_auth_token' => 'encrypted',
            'twilio_whatsapp_number' => 'encrypted',
        ];
    }

    /**
     * Cache key for cached() below — see Company::CACHE_KEY's doc comment;
     * same per-tenant auto-prefixing applies here.
     */
    public const string CACHE_KEY = 'notification_settings:current';

    /**
     * Cached read of the single NotificationSettings row, auto-creating it
     * on first access. Unlike Company (guaranteed to exist via
     * TenantDatabaseSeeder::seedCompany() for every tenant), no seeder
     * currently provisions a notification_settings row for existing
     * tenants — this table is new. firstOrCreate() with all-default values
     * (every channel off, no credentials) means the Settings page always
     * has a real row to render/update rather than requiring a migration
     * backfill or leaving the page in a "no record" state forever.
     */
    public static function cached(): self
    {
        return Cache::remember(
            self::CACHE_KEY,
            now()->addHour(),
            // The default `false` values are passed explicitly rather than
            // relying on the migration's DB-level column defaults: a plain
            // firstOrCreate([]) INSERTs successfully (Postgres fills in the
            // defaults), but the in-memory model returned to the caller
            // still has null for those attributes until a fresh SELECT —
            // Eloquent doesn't automatically re-read DB-computed defaults
            // after an insert. Passing them here means the very first
            // request after a tenant is provisioned already sees false
            // (not null) without an extra round-trip.
            fn () => static::query()->firstOrCreate([], [
                'whatsapp_enabled' => false,
                'email_enabled' => false,
                'sms_enabled' => false,
            ])
        );
    }
}
