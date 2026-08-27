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
