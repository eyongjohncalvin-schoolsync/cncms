<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Middleware\ResolveLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The language-switcher form (resources/tsx/layouts/AppLayout.tsx) —
 * updating one's own `users.locale` is a self-service action, not gated by
 * a Policy/role the way Settings/Company is.
 */
class UpdateLocaleRequest extends FormRequest
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
            'locale' => ['required', 'string', Rule::in(ResolveLocale::SUPPORTED)],
        ];
    }
}
