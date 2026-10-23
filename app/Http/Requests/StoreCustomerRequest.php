<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DataTransferObjects\InstallationData;
use App\Http\Middleware\BlockDuringImpersonation;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    /**
     * `bill` and `services` are mutually backward-compatible, not both
     * required (services.md section 6): the rebuilt Create.tsx form sends
     * `services[]` and no `bill`; App\Services\CustomerImportService (xlsx
     * import) still sends only the legacy `bill` column and no `services`
     * — its per-row validation reuses these exact rules
     * (`(new StoreCustomerRequest)->rules()`), so tightening `bill` to
     * `prohibited` here would break every import. Whichever one is sent,
     * App\Services\CustomerSubscriptionService is the single place that
     * turns it into subscription rows and the final `bill` (see
     * CustomerSubscriptionService::defaultSelection()'s $bill parameter).
     *
     * The deeper invariants — no duplicate (service, option) pair, an
     * option requires its base service also selected, unknown uuids — are
     * enforced once, in CustomerSubscriptionService::sync(), not
     * duplicated here; a ValidationException it throws surfaces as a
     * normal 422/redirect-with-errors exactly like a rule failure would.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'zone_uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:30'],
            'bill' => ['required_without:services', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'others' => ['nullable', 'numeric', 'min:0', 'max:999999999.99', 'decimal:0,2'],
            'phone' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'preferred_channel' => ['nullable', 'string', Rule::in(Customer::PREFERRED_CHANNELS)],
            'level' => ['nullable', 'string', 'in:normal,Vip,Operator'],
            'status' => ['nullable', 'string', 'in:active,passive,disconnected,suspended'],
            'services' => ['required_without:bill', 'array', 'min:1'],
            'services.*.service_uuid' => ['required_with:services', 'uuid', 'exists:services,uuid'],
            'services.*.service_variant_uuid' => ['nullable', 'uuid', 'exists:service_variants,uuid'],
            'services.*.price' => ['required_with:services', 'numeric', 'gte:0', 'max:999999999.99', 'decimal:0,2'],
            ...self::installationRules(),
        ];
    }

    /**
     * The "Installation & first payment" section of the add-customer form
     * (docs/plans/installation-and-promotions.md, phase 2) — every field
     * optional, so the xlsx import (which reuses rules()) is unaffected.
     * App\Services\CustomerInstallationService checks how they fit together.
     *
     * @return array<string, mixed>
     */
    public static function installationRules(): array
    {
        return [
            'connected_on' => ['nullable', 'date_format:Y-m-d'],
            'installation_offer_uuid' => ['nullable', 'uuid'],
            'installation_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'installation_paid' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'installation_reason' => ['nullable', 'string', 'max:500'],
            'free_months' => ['nullable', 'array'],
            'free_months.*.service_uuid' => ['required', 'uuid'],
            'free_months.*.months' => ['required', 'integer', 'min:0', 'max:36'],
            'advance_months' => ['nullable', 'integer', 'min:0', 'max:24'],
            ...Payment::methodRules(),
        ];
    }

    /**
     * Picking an offer / fee / free months is `installations.manage`; an
     * advance is a payment (`payments.create`); and no money is taken while
     * a landlord impersonates (E9).
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $context = app(TenantContext::class);
            $installation = InstallationData::fromArray($this->all());

            $touchesInstallation = $installation->offerUuid !== null || $installation->fee !== null
                || bccomp($installation->paidNow, '0', 2) > 0 || $installation->freeMonths !== null;

            if ($touchesInstallation && ! $context->can('installations.manage')) {
                $validator->errors()->add('installation_offer_uuid', 'You may not set an installation fee or free months.');
            }

            if ($installation->advanceMonths > 0 && ! $context->can('payments.create')) {
                $validator->errors()->add('advance_months', 'You may not record payments.');
            }

            $takesMoney = bccomp($installation->paidNow, '0', 2) > 0 || $installation->advanceMonths > 0;

            if ($takesMoney && Impersonation::active()) {
                $validator->errors()->add('installation_paid', BlockDuringImpersonation::MONEY_MESSAGE);
            }
        }];
    }
}
