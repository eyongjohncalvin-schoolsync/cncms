<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Landlord-only (Landlord\LegalDocumentController::saveDraft); the
 * `landlord` middleware gates the route, as for StoreMaintenanceWindowRequest.
 * The text is Markdown; raw HTML in it is shown as text, never run.
 */
class SaveLegalDraftRequest extends FormRequest
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
            'body_en' => ['required', 'string', 'max:60000'],
            'body_fr' => ['nullable', 'string', 'max:60000'],
            'change_note' => ['nullable', 'string', 'max:255'],
            'requires_acceptance' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body_en.required' => 'Write the English text — it is shown when there is no French.',
        ];
    }
}
