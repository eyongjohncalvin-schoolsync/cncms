<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk customer import via customer_upload_main.xlsx — gated by the same
 * 'create' ability as a manually-typed customer (CustomerPolicy::create(),
 * super/admin/manager). Row-level field validation happens inside
 * App\Services\CustomerImportService (reusing StoreCustomerRequest::rules()
 * per row, after resolving the spreadsheet's `zone` name to a zone_uuid) —
 * this request only gates the upload itself.
 */
class ImportCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 5MB is generous for a spreadsheet of the ~549-customer scale
            // this system runs at (max is in kilobytes per Laravel's `max`
            // file rule).
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ];
    }
}
