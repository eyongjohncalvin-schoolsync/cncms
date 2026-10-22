<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\MaintenanceWindow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Landlord-only (App\Http\Controllers\Landlord\MaintenanceWindowController::
 * store). authorize() returns true for the same reason as
 * StoreTenantRequest: the `landlord` middleware gates every route in
 * routes/web/landlord.php.
 *
 * An empty `starts_at` means "start now". Only one live window at a time
 * — overlapping windows would make "when is the system back?" ambiguous.
 */
class StoreMaintenanceWindowRequest extends FormRequest
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
            // A minute's grace: "now" (filled in below) or a just-passed
            // picker value must not fail by a few seconds.
            'starts_at' => ['required', 'date', 'after_or_equal:'.now()->subMinute()->toIso8601String()],
            'ends_at' => ['required', 'date', 'after:now', 'after:starts_at'],
            'message' => ['nullable', 'string', 'max:500'],
            'notify_by_email' => ['boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (MaintenanceWindow::query()->live()->exists()) {
                    $validator->errors()->add('starts_at', 'A maintenance window is already scheduled or running. End or cancel it first.');
                }
            },
        ];
    }

    /**
     * `after:starts_at` must not fail when starts_at is empty ("now").
     */
    protected function prepareForValidation(): void
    {
        if (blank($this->input('starts_at'))) {
            $this->merge(['starts_at' => now()->toIso8601String()]);
        }
    }
}
