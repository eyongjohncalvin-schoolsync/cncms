<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\ResolveLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * "My account" profile form (AccountController::updateProfile()): the
 * signed-in user's own name, username and language. The email address has
 * its own code-confirmed flow (RequestEmailChangeRequest).
 */
class UpdateAccountProfileRequest extends FormRequest
{
    /** Only ever the signed-in user's own row: there is no route parameter. */
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
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required', 'string', 'max:50',
                Rule::unique('pgsql.users', 'username')->ignore($this->user()->id),
            ],
            'locale' => ['required', 'string', Rule::in(ResolveLocale::SUPPORTED)],
        ];
    }
}
