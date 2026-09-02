<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\Payment;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payments. A `disconnected` or `suspended` customer must be
 * reconnected (App\Http\Requests\ReconnectCustomerRequest ->
 * App\Services\CustomerStatusService::reconnect()) before a new payment can
 * be recorded against them — `passive` is deliberately NOT blocked here,
 * that status stays payable. This has to live at this HTTP-validation
 * layer rather than in App\Services\PaymentService::create() itself:
 * CustomerStatusService::reconnectOne() calls PaymentService::create()
 * directly (a plain PHP method call, bypassing this FormRequest entirely)
 * to record the reconnection-fine payment WHILE the customer's status is
 * still `disconnected` — that call is legitimate and must keep working, so
 * the guard belongs here, upstream of it, not inside the service. The
 * inline closure below (rather than a separate Rule class) resolves the
 * related Customer record and queries its status directly inside rules() —
 * ReconnectCustomerRequest and BulkReconnectCustomersRequest used to do the
 * same for their old `fine_collected` field, before the 2026-08 owner
 * decision made the reconnection fine a plain opt-in boolean
 * (`include_fine`) with no status-dependent validation branch left to
 * resolve a customer for.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Payment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $customer = $this->filled('customer_uuid') && is_string($this->input('customer_uuid'))
            ? Customer::query()->where('uuid', $this->input('customer_uuid'))->first()
            : null;

        return [
            'customer_uuid' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, Closure $fail) use ($customer): void {
                    if ($customer && in_array($customer->status, ['disconnected', 'suspended'], true)) {
                        $fail("Cannot record a payment for {$customer->name} — they are currently {$customer->status}. Reconnect them first.");
                    }
                },
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'credit' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'frequency' => ['required', 'string', 'in:monthly,yearly,months'],
            'months' => ['required_if:frequency,months', 'nullable', 'integer', 'min:1'],
            // Draw-down Q1 — the agent's "pay down arrears first" toggle,
            // only meaningful for a months/yearly prepayment.
            'clear_arrears_first' => ['nullable', 'boolean'],
            'recorded_offline' => ['nullable', 'boolean'],
            'recorded_by_device' => ['nullable', 'string', 'max:255'],
        ];
    }
}
