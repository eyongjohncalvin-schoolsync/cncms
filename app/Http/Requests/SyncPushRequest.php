<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the shape of a POST /sync/push body (offline-sync-strategy.md
 * section 4.2 / api-spec.md section 7.1). Business-level validation — does
 * `customer_uuid`/`category_uuid` actually resolve to a record — is
 * deliberately NOT done here: that happens per-item inside
 * App\Services\SyncService::push(), so one bad reference fails only that
 * item instead of 422-ing the whole batch. `customer_uuid`/`category_uuid`
 * are therefore validated as plain strings, not `uuid` format, so a
 * syntactically-valid-but-nonexistent UUID reaches the Service layer rather
 * than being rejected here.
 */
class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role-gating (agent/manager/admin/super) happens in
        // App\Http\Controllers\Api\SyncController — every authenticated
        // tenant member is allowed to reach this far.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:255'],
            'last_sync_at' => ['nullable', 'date'],

            'changes' => ['nullable', 'array'],

            // Customers registered in the field, offline (installation
            // phase 3) — shape only; every rule is checked per item by
            // App\Services\FieldInstallationService, so one bad
            // registration fails alone. Every key is listed so validated()
            // keeps it; the nested arrays come through whole.
            'changes.installations' => ['nullable', 'array'],
            'changes.installations.*.local_uuid' => ['required', 'uuid'],
            'changes.installations.*.customer_uuid' => ['nullable'],
            'changes.installations.*.created_at' => ['nullable'],
            'changes.installations.*.zone_uuid' => ['nullable'],
            'changes.installations.*.name' => ['nullable'],
            'changes.installations.*.phone' => ['nullable'],
            'changes.installations.*.location' => ['nullable'],
            'changes.installations.*.email' => ['nullable'],
            'changes.installations.*.preferred_channel' => ['nullable'],
            'changes.installations.*.level' => ['nullable'],
            'changes.installations.*.services' => ['nullable', 'array'],
            'changes.installations.*.connected_on' => ['nullable'],
            'changes.installations.*.installation_offer_uuid' => ['nullable'],
            'changes.installations.*.installation_fee' => ['nullable'],
            'changes.installations.*.installation_paid' => ['nullable'],
            'changes.installations.*.installation_reason' => ['nullable'],
            'changes.installations.*.service_plans' => ['nullable', 'array'],
            'changes.installations.*.method' => ['nullable'],
            'changes.installations.*.method_reference' => ['nullable'],
            'changes.installations.*.installation_receipt' => ['nullable', 'array'],
            'changes.installations.*.upfront_receipt' => ['nullable', 'array'],

            // Shape only: every value rule is checked per item by
            // App\Services\SyncService::PAYMENT_ITEM_RULES, so one bad
            // payment (say an amount with three decimals) fails alone
            // instead of 422-ing — and blocking — the phone's whole queue.
            // Every key is listed so validated() keeps it.
            'changes.payments' => ['nullable', 'array'],
            'changes.payments.*' => ['array'],
            'changes.payments.*.local_uuid' => ['required', 'uuid'],
            'changes.payments.*.customer_uuid' => ['nullable'],
            'changes.payments.*.amount' => ['nullable'],
            'changes.payments.*.credit' => ['nullable'],
            'changes.payments.*.frequency' => ['nullable'],
            'changes.payments.*.months' => ['nullable'],
            'changes.payments.*.clear_arrears_first' => ['nullable'],
            'changes.payments.*.created_at' => ['nullable'],
            'changes.payments.*.method' => ['nullable'],
            'changes.payments.*.method_reference' => ['nullable'],
            'changes.payments.*.receipt_number' => ['nullable'],
            'changes.payments.*.security_code' => ['nullable'],
            'changes.payments.*.receipt_issued_at' => ['nullable'],
            'changes.payments.*.duplicate_reason' => ['nullable'],
            'changes.payments.*.duplicate_note' => ['nullable'],
            'changes.payments.*.prepayment_offer_uuid' => ['nullable'],

            // "Request a bon" from the phone
            // (docs/plans/expenditures-and-spending-controls.md, item 2) —
            // becomes a pending bon; the category is checked per item.
            'changes.bon_requests' => ['nullable', 'array'],
            'changes.bon_requests.*.local_uuid' => ['required', 'uuid'],
            'changes.bon_requests.*.category_uuid' => ['required', 'string'],
            'changes.bon_requests.*.amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'changes.bon_requests.*.payee_name' => ['nullable', 'string', 'max:150'],
            'changes.bon_requests.*.purpose' => ['required', 'string', 'max:500'],
            'changes.bon_requests.*.needed_on' => ['nullable', 'date'],
            'changes.bon_requests.*.from_collections' => ['nullable', 'boolean'],
            'changes.bon_requests.*.created_at' => ['nullable', 'date'],

            // Older app builds' "Record expense" — accepted and turned into
            // a pending bon request (never recorded as spent directly).
            'changes.expenditures' => ['nullable', 'array'],
            'changes.expenditures.*.local_uuid' => ['required', 'uuid'],
            'changes.expenditures.*.category_uuid' => ['required', 'string'],
            'changes.expenditures.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'changes.expenditures.*.description' => ['nullable', 'string'],
            'changes.expenditures.*.spent_at' => ['required', 'date'],
            'changes.expenditures.*.notes' => ['nullable', 'string'],

            // Complaint Desk mobile submission (complaint-desk.md section 7)
            // — business-level validation (does customer_uuid resolve, is it
            // required for category='customer') is deliberately left to
            // App\Services\SyncService::pushComplaint() -> ComplaintService,
            // same "one bad item fails only that item" reasoning as
            // payments/expenditures above.
            'changes.complaints' => ['nullable', 'array'],
            'changes.complaints.*.local_uuid' => ['required', 'uuid'],
            'changes.complaints.*.category' => ['required', 'string', 'in:operational,customer'],
            'changes.complaints.*.title' => ['required', 'string', 'max:255'],
            'changes.complaints.*.description' => ['required', 'string'],
            'changes.complaints.*.urgent' => ['nullable', 'boolean'],
            'changes.complaints.*.customer_uuid' => ['nullable', 'string'],
            'changes.complaints.*.created_at' => ['nullable', 'date'],
        ];
    }
}
