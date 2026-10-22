<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\CurrentPasswordMatches;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-enter the password before a sensitive account action: new recovery
 * codes, or signing out every other session and device.
 */
class ConfirmPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', new CurrentPasswordMatches],
        ];
    }
}
