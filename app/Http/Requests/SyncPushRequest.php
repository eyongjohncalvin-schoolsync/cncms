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

            'changes.payments' => ['nullable', 'array'],
            'changes.payments.*.local_uuid' => ['required', 'uuid'],
            'changes.payments.*.customer_uuid' => ['required', 'string'],
            'changes.payments.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'changes.payments.*.credit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'changes.payments.*.frequency' => ['required', 'string', 'in:monthly,yearly,months'],
            'changes.payments.*.months' => ['nullable', 'integer', 'min:1'],
            'changes.payments.*.clear_arrears_first' => ['nullable', 'boolean'],
            'changes.payments.*.created_at' => ['nullable', 'date'],

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
