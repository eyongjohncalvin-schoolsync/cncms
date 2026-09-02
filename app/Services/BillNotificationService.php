<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Support\CameroonPhone;
use Illuminate\Support\Carbon;

/**
 * Manual (free, no-Twilio) WhatsApp bill reminders — see
 * .ai/skills/cncms/cncms-context/references/bill-notifications.md sections
 * 1-2 and 6.2. Composes a plain-text bill reminder and a wa.me deep link
 * that a staff member opens to send it themselves from their own WhatsApp
 * session. No Twilio call, no template-approval requirement — that's
 * exactly what makes this "manual mode" free and frictionless per the
 * design doc; bulk/Twilio sending is a later phase and is NOT built here.
 *
 * English only for this pass — French comes later once the in-progress
 * language-support infrastructure has translatable strings ready
 * (see bill-notifications.md's scope note; not integrated prematurely).
 */
final class BillNotificationService
{
    /**
     * Plain-text bill reminder for $customer, built from a Manuscript
     * (defaults to $customer->latestManuscript — see that relation's doc
     * comment on App\Models\Customer for why it's ordered by `period`, not
     * `created_at`) and the tenant's Company record. Returns null when:
     *  - the customer is not ACTIVE (owner decision, 2026-08): a
     *    disconnected/suspended/passive customer is frozen with a 0
     *    total_bill, so sending them a bill reminder is wrong. This is the
     *    single guard both the web (ManuscriptController::index's wa_link
     *    column / ::sendBill()) and API (Api\BillController::whatsappMessage())
     *    paths inherit — mirrors ManuscriptService::billData()'s refusal for
     *    the printed slip.
     *  - the customer has no manuscript yet — nothing real to remind them
     *    about.
     *
     * $manuscript can be passed explicitly by a caller that already has one
     * loaded (e.g. Manuscripts/Index's per-row listing, which is scoped to
     * a specific period) to avoid re-querying latestManuscript for every
     * row.
     */
    public function composeMessage(Customer $customer, ?Manuscript $manuscript = null): ?string
    {
        if ($customer->status !== 'active') {
            return null;
        }

        $manuscript ??= $customer->latestManuscript;

        if (! $manuscript instanceof Manuscript) {
            return null;
        }

        $company = Company::cached();
        // `!Y-m` — reset day/time to base first so a short target month never
        // rolls forward when this runs on the 29th–31st (see the fuller note
        // in ManuscriptService::buildBillData()).
        $periodLabel = Carbon::createFromFormat('!Y-m', $manuscript->period)->format('F Y');
        $deadline = '05 '.$periodLabel;

        // total_bill (bill + total_arrears - credit, clamped to 0 — see
        // App\Services\ManuscriptCalculator's doc comment) is "what the
        // customer currently owes right now", i.e. this period's bill plus
        // any carried-forward arrears net of credit. total_arrears alone
        // would omit the current period's own bill and understate what's
        // actually due.
        $amount = number_format((float) $manuscript->total_bill, 0, '.', ',');

        $lines = [
            "Hello {$customer->name}, this is a reminder from ".($company?->name ?? 'us').'.',
            "Your current bill for {$periodLabel} is {$amount} FCFA, due by {$deadline}.",
        ];

        if ($company?->momo_number) {
            $lines[] = 'Pay via MOMO: '.$company->momo_number.($company->momo_name ? " ({$company->momo_name})" : '');
        }

        $lines[] = 'Thank you for staying connected.';

        return implode(' ', $lines);
    }

    /**
     * wa.me deep link for $customer, pre-filled with composeMessage()'s
     * text, or null when:
     *  - they have no phone number on file (business-rules.md notes ~78%
     *    of customers have none — this must be handled explicitly, not
     *    silently produce a broken link), or
     *  - their phone number doesn't normalize to a plausible Cameroon
     *    mobile number (see normalizePhoneForWhatsapp()), or
     *  - composeMessage() returned null — they have no manuscript yet, or
     *    they are not an active customer (see that method).
     *
     * See composeMessage() for the optional $manuscript parameter.
     */
    public function waLink(Customer $customer, ?Manuscript $manuscript = null): ?string
    {
        $phone = $this->normalizePhoneForWhatsapp($customer->phone);

        if ($phone === null) {
            return null;
        }

        $message = $this->composeMessage($customer, $manuscript);

        if ($message === null) {
            return null;
        }

        return "https://wa.me/{$phone}?text=".rawurlencode($message);
    }

    /**
     * Public wrapper around normalizePhoneForWhatsapp() for callers that
     * need the normalized phone number by itself rather than a single
     * collapsed wa.me link — specifically Api\BillController::
     * whatsappMessage() (the mobile "Send Bill via WhatsApp" endpoint),
     * which needs to tell "no phone on file" apart from "no manuscript
     * yet" instead of getting one undifferentiated null back from waLink().
     */
    public function normalizedPhone(Customer $customer): ?string
    {
        return $this->normalizePhoneForWhatsapp($customer->phone);
    }

    /**
     * Normalizes a raw customers.phone value into the digits-only,
     * country-code-prefixed form wa.me requires. Delegates to the canonical
     * App\Support\CameroonPhone::forWhatsapp() — the same normaliser
     * App\Services\ReceiptWhatsAppService uses — so the rule lives in one
     * place. See that class for the full behaviour / edge cases.
     */
    private function normalizePhoneForWhatsapp(?string $phone): ?string
    {
        return CameroonPhone::forWhatsapp($phone);
    }
}
