<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\LoginChallenge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The second sign-in step for a user with two-factor on: web
 * (TwoFactorChallengeController, the challenge token lives in the session)
 * and API (Api\TwoFactorChallengeController, the app sends it back as
 * `challenge_token`). Whether `method` is allowed for this particular
 * challenge is decided by LoginChallengeService::passes().
 */
class TwoFactorChallengeRequest extends FormRequest
{
    /** Guests by definition: the user is not signed in yet. */
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
            'challenge_token' => [$this->is('api/*') ? 'required' : 'nullable', 'string', 'max:100'],
            'method' => ['required', 'string', Rule::in([
                LoginChallenge::METHOD_TOTP,
                LoginChallenge::METHOD_RECOVERY,
                LoginChallenge::METHOD_EMAIL,
            ])],
            'code' => ['required', 'string', 'max:32'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
