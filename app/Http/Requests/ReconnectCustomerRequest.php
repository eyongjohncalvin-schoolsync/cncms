<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH .../customers/{customer}/reconnect. business-rules.md section 6
 * (2026-08 owner decision): the 2,000 FCFA reconnection fine is purely
 * admin-discretion opt-in now, never automatic or required, for
 * reconnection from EITHER `disconnected` or `suspended`. `include_fine`
 * is a plain optional boolean (the frontend's "Include reconnection fine"
 * checkbox, unchecked by default) — no `accepted`/required validation for
 * either status, unlike the old `fine_collected` confirmation this field
 * replaced. The actual charge (and the fine's Payment record) lives in
 * App\Services\CustomerStatusService::reconnect() — this only gates the
 * HTTP-layer input.
 *
 * `arrears_payment` is an OPTIONAL amount (single-customer reconnect only —
 * there is no bulk equivalent, see BulkReconnectCustomersRequest, which
 * deliberately does not carry this field) letting the admin record a full or
 * partial payment against the customer's outstanding arrears as part of the
 * same reconnect action. Blank/omitted means "no arrears payment right now,
 * just reconnect" — a fully valid, common case, not a required field. It is
 * intentionally NOT validated against the customer's actual arrears figure
 * (CustomerStatusService::reconnectOne()'s doc comment) — only that it's a
 * non-negative amount within the same sane ceiling StorePaymentRequest uses
 * for a normal payment amount. `0` is accepted (and treated identically to
 * omitting the field entirely) precisely so "leave it blank/0" stays a
 * valid, unremarkable submission rather than a validation error.
 */
class ReconnectCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reconnect', $this->route('customer'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            'include_fine' => ['sometimes', 'boolean'],
            'arrears_payment' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
        ];
    }
}
