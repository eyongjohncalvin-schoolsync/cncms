<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Landlord-only (App\Http\Controllers\Landlord\TenantController::store).
 * authorize() returns true rather than delegating to a Policy: every
 * route in routes/web/landlord.php is already gated end-to-end by the
 * `landlord` middleware alias (App\Http\Middleware\EnsureLandlord), so a
 * per-action Policy here would just re-check the same "super for swecom"
 * condition the middleware already enforced before this request handler
 * ever runs.
 */
class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // The slug becomes the tenant's `id` (its Postgres schema name
            // is derived from it — see config/tenancy.php's 'prefix'), so
            // it must be unique against the real primary key column, and
            // URL/schema-name safe.
            'slug' => ['required', 'string', 'max:63', 'alpha_dash', 'unique:tenants,id'],
        ];
    }
}
