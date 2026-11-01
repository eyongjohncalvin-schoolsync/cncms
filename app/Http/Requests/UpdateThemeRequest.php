<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The header's light/dark switch (resources/tsx/components/shared/
 * ThemeSwitcher.tsx) — one's own `users.ui_theme`, self-service like the
 * language switcher (UpdateLocaleRequest).
 */
class UpdateThemeRequest extends FormRequest
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
            'theme' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
        ];
    }
}
