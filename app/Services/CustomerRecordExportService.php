<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ArrearsAdjustment;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\Message;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Gathers EVERYTHING CNCMS holds about one customer into a single, fully
 * typed nested array — the data layer behind
 * App\Http\Controllers\CustomerRecordExportController (PDF + multi-sheet
 * XLSX). See docs/plans/customer-record-export.md.
 *
 * Design rules for this class:
 *
 *   - ONE method per output section (profile / payments / manuscripts /
 *     arrears_adjustments / messages / complaints / status_history /
 *     audit_trail). Adding a future section = one new private method + one
 *     line in gather(). Nothing else in the app needs to change.
 *   - Newest-first within every section (the auditor reads top-down from
 *     "what happened last").
 *   - Every row is a plain array of scalars/strings — no Eloquent models
 *     leak out — so the PDF blade and the XLSX sheets consume the identical
 *     shape and can never disagree.
 *   - Watch N+1: each section eager-loads its own relations up front.
 *
 * This is a COMPLETE, UNREDACTED record (there is no redaction step — plan
 * Non-goals), which is exactly why the `customers.export_record` permission
 * that gates the controller is seeded super/admin only.
 */
final class CustomerRecordExportService
{
    /**
     * The audit-trail row cap. A long-lived customer can accumulate
     * thousands of audit_logs rows (every manuscript recalculation writes
     * one per month); an export is a snapshot for a dispute, not a full
     * forensic dump, so we take the most recent N and flag truncation in
     * the output.
     */
    public const int AUDIT_TRAIL_CAP = 500;

    /**
     * @return array{
     *     meta: array<string, mixed>,
     *     profile: array<string, mixed>,
     *     payments: list<array<string, mixed>>,
     *     manuscripts: list<array<string, mixed>>,
     *     arrears_adjustments: list<array<string, mixed>>,
     *     messages: list<array<string, mixed>>,
     *     complaints: list<array<string, mixed>>,
     *     status_history: list<array<string, mixed>>,
     *     audit_trail: array{entries: list<array<string, mixed>>, truncated: bool, cap: int, total: int}
     * }
     */
    public function gather(Customer $customer): array
    {
        // Bind the payments + manuscripts collections once here — the
        // audit_trail section needs their uuids, and re-querying them would
        // be wasteful.
        $payments = $this->loadPayments($customer);
        $manuscripts = $this->loadManuscripts($customer);

        return [
            'meta' => $this->meta($customer),
            'profile' => $this->profile($customer),
            'payments' => $this->payments($payments),
            'manuscripts' => $this->manuscripts($manuscripts),
            'arrears_adjustments' => $this->arrearsAdjustments($customer),
            'messages' => $this->messages($customer),
            'complaints' => $this->complaints($customer),
            'status_history' => $this->statusHistory($customer),
            'audit_trail' => $this->auditTrail($customer, $payments, $manuscripts),
        ];
    }

    // -----------------------------------------------------------------
    // meta — provenance of this document
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function meta(Customer $customer): array
    {
        return [
            'customer_uuid' => $customer->uuid,
            'customer_name' => $customer->name,
            'generated_at' => now()->toIso8601String(),
            'generated_by' => auth()->user()?->name ?? 'System',
            'note' => 'Complete, unredacted record of everything CNCMS holds about this customer, '
                .'produced for audit or dispute verification. Sections are newest-first. The audit '
                .'trail is capped at the '.self::AUDIT_TRAIL_CAP.' most recent entries.',
        ];
    }

    // -----------------------------------------------------------------
    // profile — every `customers` column + zone/branch + lifecycle state
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function profile(Customer $customer): array
    {
        $customer->loadMissing(['zone.branch', 'archivedBy']);

        return [
            'uuid' => $customer->uuid,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'location' => $customer->location,
            'description' => $customer->description,
            'bill' => $customer->bill,
            // `others` is the initial carried-over balance seeded at import
            // (business-rules.md) — part of the ledger picture, so included.
            'others' => $customer->others,
            'level' => $customer->level,
            'status' => $customer->status,
            'status_reason' => $customer->status_reason,
            'status_note' => $customer->status_note,
            'status_changed_at' => $this->iso($customer->status_changed_at),
            'prepaid_paused' => $customer->prepaid_paused,
            'zone' => $customer->zone?->name,
            'branch' => $customer->zone?->branch?->name,
            // Soft-delete / archive state (Customer is the app's only
            // SoftDeletes model).
            'archived' => $customer->trashed(),
            'archived_at' => $this->iso($customer->deleted_at),
            'archived_by' => $customer->archivedBy?->name,
            'archived_reason' => $customer->archived_reason,
            'created_at' => $this->iso($customer->created_at),
            'updated_at' => $this->iso($customer->updated_at),
        ];
    }

    // -----------------------------------------------------------------
    // payments — every `payments` row + its verification + its receipt
    // -----------------------------------------------------------------

    /**
     * @return Collection<int, Payment>
     */
    private function loadPayments(Customer $customer): Collection
    {
        return $customer->payments()
            ->with(['verification.verifier', 'receipt.issuedBy'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @return list<array<string, mixed>>
     */
    private function payments(Collection $payments): array
    {
        return $payments->map(function (Payment $payment): array {
            $verification = $payment->verification;
            $receipt = $payment->receipt;

            return [
                'uuid' => $payment->uuid,
                'amount' => $payment->amount,
                'credit' => $payment->credit,
                'method' => $payment->frequency,
                'frequency' => $payment->frequency,
                'months' => $payment->months,
                'prepaid_rate' => $payment->prepaid_rate,
                'clear_arrears_first' => $payment->clear_arrears_first,
                'expiration_date' => $this->date($payment->expiration_date),
                'verification_status' => $payment->verification_status,
                'processed_at' => $this->iso($payment->processed_at),
                'processed_period' => $payment->processed_period,
                'recorded_offline' => $payment->recorded_offline,
                'collected_at' => $this->iso($payment->collected_at),
                'created_at' => $this->iso($payment->created_at),
                // The approval workflow row (payment_verifications).
                'verification' => $verification === null ? null : [
                    'momo_ref' => $verification->momo_ref,
                    'momo_status' => $verification->momo_status,
                    'status' => $verification->status,
                    'verified_by' => $verification->verifier?->name,
                    'verified_at' => $this->iso($verification->verified_at),
                    'notes' => $verification->notes,
                ],
                // The business-issued receipt (payment_receipts, at most one).
                'receipt' => $receipt === null ? null : [
                    'number' => $receipt->receipt_number,
                    'issued_at' => $this->iso($receipt->issued_at),
                    'issued_by' => $receipt->issuedBy?->name,
                    'status' => $receipt->status,
                ],
            ];
        })->values()->all();
    }

    // -----------------------------------------------------------------
    // manuscripts — every billing period + the command run that wrote it
    // -----------------------------------------------------------------

    /**
     * @return Collection<int, Manuscript>
     */
    private function loadManuscripts(Customer $customer): Collection
    {
        return $customer->manuscripts()
            ->with('commandRun')
            // `period` is a 'YYYY-MM' string that sorts chronologically —
            // the same ordering Customer::latestManuscript() relies on, and
            // deliberately NOT created_at (rows are backfilled out of
            // calendar order).
            ->orderByDesc('period')
            ->get();
    }

    /**
     * @param  Collection<int, Manuscript>  $manuscripts
     * @return list<array<string, mixed>>
     */
    private function manuscripts(Collection $manuscripts): array
    {
        return $manuscripts->map(fn (Manuscript $manuscript): array => [
            'uuid' => $manuscript->uuid,
            'period' => $manuscript->period,
            'bill' => $manuscript->bill,
            'total_arrears' => $manuscript->total_arrears,
            'credit' => $manuscript->credit,
            'total_bill' => $manuscript->total_bill,
            'payment_expiration' => $this->date($manuscript->payment_expiration),
            'prepaid_months_remaining' => (int) $manuscript->prepaid_months_remaining,
            'prepaid_rate' => $manuscript->prepaid_rate,
            'coverage_through' => $manuscript->expiryLabel(),
            'command_run_id' => $manuscript->command_run_id,
            'command_run' => $manuscript->commandRun?->command,
            'created_at' => $this->iso($manuscript->created_at),
            'updated_at' => $this->iso($manuscript->updated_at),
        ])->values()->all();
    }

    // -----------------------------------------------------------------
    // arrears_adjustments — every maker-checker ledger correction
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function arrearsAdjustments(Customer $customer): array
    {
        return ArrearsAdjustment::query()
            ->where('customer_id', $customer->id)
            ->with(['requestedBy', 'approvedBy', 'secondApprovedBy'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ArrearsAdjustment $adjustment): array => [
                'uuid' => $adjustment->uuid,
                'direction' => $adjustment->direction,
                'target' => $adjustment->target ?? 'arrears',
                'amount' => $adjustment->amount,
                'target_period' => $adjustment->target_period,
                'reason_category' => $adjustment->reason_category,
                'reason_note' => $adjustment->reason_note,
                'status' => $adjustment->status,
                'requested_by' => $adjustment->requestedBy?->name,
                'approved_by' => $adjustment->approvedBy?->name,
                'second_approved_by' => $adjustment->secondApprovedBy?->name,
                'rejection_reason' => $adjustment->rejection_reason,
                'requested_at' => $this->iso($adjustment->created_at),
                'approved_at' => $this->iso($adjustment->approved_at),
                'second_approved_at' => $this->iso($adjustment->second_approved_at),
                'processed_at' => $this->iso($adjustment->processed_at),
                'processed_period' => $adjustment->processed_period,
            ])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // messages — every SMS / WhatsApp / bill notification logged
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(Customer $customer): array
    {
        return Message::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Message $message): array => [
                'uuid' => $message->uuid,
                'type' => $message->type,
                'channel' => $message->channel,
                'status' => $message->status,
                'sid' => $message->sid,
                'content' => $message->content,
                'created_at' => $this->iso($message->created_at),
            ])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // complaints — every Complaint Desk record for this customer
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function complaints(Customer $customer): array
    {
        return Complaint::query()
            ->where('customer_id', $customer->id)
            ->with(['assignedTo', 'submittedBy', 'resolvedBy', 'escalations'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Complaint $complaint): array {
                // There is no `escalation_level` column — the level is the
                // highest level the escalation engine has fired for this
                // complaint (0 = never escalated). `escalated_at` is set
                // once, only at the 48h "full staff" threshold.
                $level = (int) $complaint->escalations->max('level');

                return [
                    'uuid' => $complaint->uuid,
                    'category' => $complaint->category,
                    'title' => $complaint->title,
                    'description' => $complaint->description,
                    'urgent' => $complaint->urgent,
                    'status' => $complaint->status,
                    'escalation_level' => $level,
                    'escalated_at' => $this->iso($complaint->escalated_at),
                    'assigned_to' => $complaint->assignedTo?->name,
                    'submitted_by' => $complaint->submittedBy?->name,
                    'resolved_by' => $complaint->resolvedBy?->name,
                    'resolution_notes' => $complaint->resolution_notes,
                    'created_at' => $this->iso($complaint->created_at),
                    'resolved_at' => $this->iso($complaint->resolved_at),
                ];
            })
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // status_history — there is NO dedicated table, so derive it from the
    // customer's own audit_logs update rows where `status` actually changed
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function statusHistory(Customer $customer): array
    {
        return AuditLog::query()
            ->where('table_name', 'customers')
            ->where('record_uuid', $customer->uuid)
            ->where('action', 'update')
            ->with('user')
            ->orderByDesc('created_at')
            ->get()
            ->filter(function (AuditLog $log): bool {
                $old = $log->old_values['status'] ?? null;
                $new = $log->new_values['status'] ?? null;

                return $old !== null && $new !== null && $old !== $new;
            })
            ->map(fn (AuditLog $log): array => [
                'from' => $log->old_values['status'] ?? null,
                'to' => $log->new_values['status'] ?? null,
                'reason' => $log->new_values['status_reason'] ?? null,
                'note' => $log->new_values['status_note'] ?? null,
                'changed_by' => $log->user?->name,
                'ip_address' => $log->ip_address,
                'changed_at' => $this->iso($log->created_at),
            ])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // audit_trail — every audit_logs row for this customer AND for its
    // payments / manuscripts, newest-first, capped
    // -----------------------------------------------------------------

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  Collection<int, Manuscript>  $manuscripts
     * @return array{entries: list<array<string, mixed>>, truncated: bool, cap: int, total: int}
     */
    private function auditTrail(Customer $customer, Collection $payments, Collection $manuscripts): array
    {
        $uuids = collect([$customer->uuid])
            ->merge($payments->pluck('uuid'))
            ->merge($manuscripts->pluck('uuid'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $base = AuditLog::query()->whereIn('record_uuid', $uuids);

        $total = (clone $base)->count();

        $entries = $base
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(self::AUDIT_TRAIL_CAP)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'table' => $log->table_name,
                'record_uuid' => $log->record_uuid,
                'action' => $log->action,
                'changes' => $this->diff($log),
                'user' => $log->user?->name,
                'ip_address' => $log->ip_address,
                'created_at' => $this->iso($log->created_at),
            ])
            ->values()
            ->all();

        return [
            'entries' => $entries,
            'truncated' => $total > self::AUDIT_TRAIL_CAP,
            'cap' => self::AUDIT_TRAIL_CAP,
            'total' => $total,
        ];
    }

    /**
     * The old -> new value diff for one audit row: only the keys that
     * actually changed (a create lists every new value; a delete lists
     * every value that existed).
     *
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    private function diff(AuditLog $log): array
    {
        $old = $log->old_values ?? [];
        $new = $log->new_values ?? [];

        $fields = collect(array_keys($old + $new))->unique();

        return $fields
            ->map(fn (string $field): array => [
                'field' => $field,
                'old' => $old[$field] ?? null,
                'new' => $new[$field] ?? null,
            ])
            ->filter(fn (array $row): bool => $row['old'] !== $row['new'])
            ->values()
            ->all();
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function iso(?Carbon $value): ?string
    {
        return $value?->toIso8601String();
    }

    private function date(?Carbon $value): ?string
    {
        return $value?->toDateString();
    }
}
