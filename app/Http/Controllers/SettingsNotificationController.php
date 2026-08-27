<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNotificationSettingRequest;
use App\Models\NotificationSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings — Notifications (bill-notifications.md sections 3 and 6.1-6.2).
 * Single-row settings table, same "no Service/Repository layer" deliberate
 * simplification as SettingsCompanyController.
 */
class SettingsNotificationController extends Controller
{
    public function edit(): Response
    {
        $this->authorize('view', NotificationSetting::class);

        $settings = NotificationSetting::cached();

        return Inertia::render('Settings/Notifications', [
            'settings' => [
                'uuid' => $settings->uuid,
                'whatsapp_enabled' => $settings->whatsapp_enabled,
                'email_enabled' => $settings->email_enabled,
                'sms_enabled' => $settings->sms_enabled,
                'twilio_account_sid' => $settings->twilio_account_sid,
                'twilio_auth_token' => $settings->twilio_auth_token,
                'twilio_whatsapp_number' => $settings->twilio_whatsapp_number,
            ],
            // Landlord-controlled entitlement (Tenant::bulkWhatsappEnabled)
            // for BULK Twilio WhatsApp specifically — the manual wa.me mode
            // needs no entitlement and isn't gated by this at all. `tenant()`
            // resolves the currently-initialized App\Models\Tenant instance
            // (see config/tenancy.php's 'tenant_model').
            'bulk_whatsapp_entitled' => (bool) tenant()->bulk_whatsapp_enabled,
        ]);
    }

    public function update(UpdateNotificationSettingRequest $request): RedirectResponse
    {
        $settings = NotificationSetting::cached();

        $settings->update($request->validated());

        Cache::forget(NotificationSetting::CACHE_KEY);

        return redirect()->route('settings.notifications.edit')->with('success', 'Notification settings updated.');
    }
}
