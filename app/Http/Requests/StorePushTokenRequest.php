<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/devices/push-token. Ungated at the FormRequest level beyond
 * "is an authenticated tenant user" (same shape as StoreComplaintRequest) —
 * every role may register a device for push; whether they actually ever
 * receive a push is decided later, per-notification, by
 * App\Repositories\Eloquent\PushTokenRepository::tokensForAudience()
 * (agent-role only). Registering a token for a non-agent role is harmless
 * (it simply never gets used) and not worth blocking client-side.
 */
class StorePushTokenRequest extends FormRequest
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
            'device_id' => ['required', 'string', 'max:100'],
            'expo_push_token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:ios,android'],
        ];
    }
}
