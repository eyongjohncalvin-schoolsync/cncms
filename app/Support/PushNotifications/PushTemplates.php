<?php

declare(strict_types=1);

namespace App\Support\PushNotifications;

/**
 * Small, fixed, server-side title/body templates for push notifications —
 * see App\Jobs\SendPushNotificationJob's class doc for why. The
 * notifications.title/notifications.body columns are NEVER interpolated
 * into a push payload: they're free-form (a complaint's title/description
 * may name a customer directly) and a lock-screen-visible push must never
 * leak that. Keyed by "{type}.{severity}" with a per-severity fallback for
 * any type this table doesn't know about, so a future notification type
 * that starts pushing before its own template is added still gets a safe,
 * generic message rather than raw content or a crash.
 */
final class PushTemplates
{
    /**
     * @return array{0: array<string, array{title: string, body: string}>, 1: array<string, array{title: string, body: string}>}
     */
    private const TABLE = [
        // The manager inbox's alerts (App\Services\ActionAlertService).
        'inbox.voucher' => [
            'urgent' => ['title' => 'A voucher needs your approval', 'body' => 'Tap to decide in your inbox.'],
        ],
        'voucher.stakeholder_approval' => [
            'urgent' => ['title' => 'A voucher needs your approval', 'body' => 'It is above the limits — every stakeholder must approve. Tap to decide.'],
        ],
        'inbox.arrears' => [
            'warning' => ['title' => 'An arrears adjustment needs approval', 'body' => 'Tap to decide in your inbox.'],
        ],
        'inbox.reversal' => [
            'warning' => ['title' => 'A collector asks to reverse a payment', 'body' => 'Tap to decide in your inbox.'],
        ],
        'inbox.complaint' => [
            'urgent' => ['title' => 'An urgent complaint needs a technician', 'body' => 'Tap to assign someone.'],
            'warning' => ['title' => 'A new complaint needs a technician', 'body' => 'Tap to assign someone.'],
        ],
        'inbox.activation' => [
            'warning' => ['title' => 'A new customer waits for activation', 'body' => 'Registered in the field. Open the website to activate.'],
        ],
        'inbox.escalated' => [
            'urgent' => ['title' => 'Waiting too long', 'body' => 'Something has waited hours for a decision. Tap to see it.'],
        ],
        'inbox.digest' => [
            'warning' => ['title' => 'Your morning summary', 'body' => 'Tap to see what waits for you today.'],
        ],
        // Personal warnings that reach the phone silently since wave 2.
        'voucher.rejected' => [
            'warning' => ['title' => 'Your voucher was rejected', 'body' => 'Tap to see why.'],
        ],
        'remittance.received' => [
            'warning' => ['title' => 'Your handover was short', 'body' => 'Tap to see the details.'],
        ],
        'complaint.assigned' => [
            'urgent' => [
                'title' => 'A complaint was assigned to you',
                'body' => 'Tap to review and take action.',
            ],
        ],
        'complaint.escalated' => [
            'urgent' => [
                'title' => 'A complaint needs team attention',
                'body' => 'This complaint has been open a while without action. Tap to review.',
            ],
            'emergency' => [
                'title' => 'Urgent: a complaint needs your attention',
                'body' => 'Open 48+ hours with no action taken. Tap to review and acknowledge.',
            ],
        ],
        // Resolution notices are severity='info' in practice (see
        // App\Services\ComplaintEscalationService::sendResolutionNotice())
        // and never actually reach SendPushNotificationJob (which only
        // dispatches for urgent/emergency) — this entry exists purely for
        // the table's own completeness/correctness, per the build spec.
        'complaint.resolved' => [
            'info' => [
                'title' => 'A complaint has been resolved',
                'body' => 'Tap to view the details.',
            ],
        ],
    ];

    /**
     * Per-severity fallback used whenever "{type}.{severity}" has no entry
     * above — keeps this table additive (a new notification `type` that
     * starts using severity urgent/emergency before anyone adds it here
     * still pushes something sane) rather than a hard requirement to touch
     * this file for every new type.
     */
    private const FALLBACK = [
        'warning' => [
            'title' => 'New update',
            'body' => 'Tap to view.',
        ],
        'urgent' => [
            'title' => 'New alert',
            'body' => 'Tap to review.',
        ],
        'emergency' => [
            'title' => 'Emergency alert',
            'body' => 'Tap to review and acknowledge immediately.',
        ],
    ];

    /**
     * @return array{title: string, body: string}
     */
    public static function resolve(string $type, string $severity): array
    {
        return self::TABLE[$type][$severity]
            ?? self::FALLBACK[$severity]
            ?? ['title' => 'Notification', 'body' => 'Tap to review.'];
    }
}
