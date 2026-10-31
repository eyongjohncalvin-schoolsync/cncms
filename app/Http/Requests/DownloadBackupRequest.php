<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\CurrentPasswordMatches;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Landlord-only (App\Http\Controllers\Landlord\BackupController::download);
 * the `landlord` middleware gates the route, as for StoreMaintenanceWindowRequest.
 *
 * The account password proves it is really the landlord at the keyboard;
 * the zip password is what the downloaded file is locked with.
 */
class DownloadBackupRequest extends FormRequest
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
            'password' => ['required', 'string', new CurrentPasswordMatches],
            'zip_password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zip_password.min' => 'Use at least 12 characters to lock the file.',
            'zip_password.confirmed' => 'The two file passwords do not match.',
        ];
    }
}
