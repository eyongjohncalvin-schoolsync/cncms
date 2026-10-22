<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\CurrentPasswordMatches;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start an email change (web AccountController / API
 * Api\AccountSecurityController): a code goes to the NEW address and the
 * change only happens once it comes back. The current password is required
 * so a borrowed, signed-in device cannot redirect the account's email.
 */
class RequestEmailChangeRequest extends FormRequest
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
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::notIn([$this->user()->email]),
                Rule::unique('pgsql.users', 'email'),
            ],
            'current_password' => ['required', 'string', new CurrentPasswordMatches],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.not_in' => 'That is already your email address.',
        ];
    }
}
