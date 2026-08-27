<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Landlord-only (App\Http\Controllers\Landlord\TenantController::update).
 * See StoreTenantRequest's doc comment for why authorize() returns true
 * instead of delegating to a Policy — the `landlord` middleware alias
 * already gates every route in routes/web/landlord.php.
 *
 * The active/inactive toggle and the bulk-WhatsApp entitlement toggle
 * (Tenant::bulkWhatsappEnabled) are each submitted from their own <Form> on
 * Landlord/Tenants/Edit.tsx, so both fields are 'sometimes' rather than
 * 'required' — a given request may carry only one of them. Name/slug are
 * fixed once a tenant's schema has been provisioned and aren't editable
 * here at all.
 */
class UpdateTenantRequest extends FormRequest
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
            'is_active' => ['sometimes', 'boolean'],
            'bulk_whatsapp_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
