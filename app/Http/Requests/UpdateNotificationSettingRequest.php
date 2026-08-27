<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\NotificationSetting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', NotificationSetting::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'whatsapp_enabled' => ['required', 'boolean'],
            'email_enabled' => ['required', 'boolean'],
            'sms_enabled' => ['required', 'boolean'],
            // Nullable: a tenant can toggle whatsapp_enabled on without
            // (yet) filling in Twilio credentials, or clear a previously
            // saved credential by submitting an empty string. These fields
            // are also only meaningful once the landlord's bulk_whatsapp
            // entitlement is on (Settings/Notifications.tsx hides/disables
            // them otherwise) — SettingsNotificationController::update()
            // does not itself re-check the entitlement server-side beyond
            // this validation, since saving a Twilio credential the tenant
            // isn't yet entitled to use is inert (bulk-send logic is a
            // later phase and doesn't exist yet to consume it).
            'twilio_account_sid' => ['nullable', 'string', 'max:191'],
            'twilio_auth_token' => ['nullable', 'string', 'max:191'],
            'twilio_whatsapp_number' => ['nullable', 'string', 'max:30'],
        ];
    }
}
