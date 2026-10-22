<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Landlord-only: move a live window's end time (extend an overrunning
 * cutover, or bring it forward) — App\Http\Controllers\Landlord\
 * MaintenanceWindowController::update(). See StoreMaintenanceWindowRequest
 * for why authorize() returns true.
 */
class UpdateMaintenanceWindowRequest extends FormRequest
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
        $window = $this->route('maintenanceWindow');

        return [
            'ends_at' => ['required', 'date', 'after:now', 'after:'.$window->starts_at->toIso8601String()],
            'notify_by_email' => ['boolean'],
        ];
    }
}
