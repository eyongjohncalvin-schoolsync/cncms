<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Complaint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /complaints. Deliberately ungated at the FormRequest level beyond
 * "is an authenticated tenant user" — ComplaintPolicy::create() is
 * unconditionally true for all five roles (see its class doc), matching
 * references/complaint-desk.md section 3's "the one feature `worker` gets
 * genuine capability in."
 *
 * `customer_uuid` enforces references/complaint-desk.md section 1's table:
 * required for `category = 'customer'`, forbidden for `category =
 * 'operational'` — a FormRequest rule, not a DB constraint, per section 2's
 * note on customer_id.
 *
 * No `urgent`/priority field beyond the plain boolean fast-path toggle —
 * see section 6: there is deliberately no graded severity/priority input
 * for the submitter to fill in.
 */
class StoreComplaintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Complaint::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:operational,customer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'urgent' => ['nullable', 'boolean'],
            'customer_uuid' => ['required_if:category,customer', 'prohibited_if:category,operational', 'uuid'],
        ];
    }
}
