<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\CurrentPasswordMatches;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning two-factor off needs BOTH the password and a current
 * authenticator (or recovery) code; the code is checked in the controller
 * via TwoFactorService.
 */
class DisableTwoFactorRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32'],
        ];
    }
}
