<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Agent;
use App\Models\TenantUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Create login" on the Agents page: turns an old agent record without a
 * login into a field-agent user (owner, 2026-10-28) — the same account the
 * Users page creates, linked to the existing record and its zones.
 */
class CreateAgentLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Agent $agent */
        $agent = $this->route('agent');

        return $this->user()->can('create', TenantUser::class) && $this->user()->can('update', $agent);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'unique:pgsql.users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:pgsql.users,email'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }
}
