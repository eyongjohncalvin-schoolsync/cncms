<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\OpeningBalanceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveOpeningBalanceEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('openingBalanceBatch'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $maxAmount = OpeningBalanceService::MAX_AMOUNT;

        return [
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.customer_uuid' => ['required', 'uuid', 'distinct'],
            'rows.*.arrears' => ['nullable', 'numeric', 'min:0', "max:{$maxAmount}", 'decimal:0,2'],
            'rows.*.advance_amount' => ['nullable', 'numeric', 'min:0', "max:{$maxAmount}", 'decimal:0,2'],
            'rows.*.advance_months' => ['nullable', 'integer', 'min:0', 'max:'.OpeningBalanceService::MAX_ADVANCE_MONTHS],
            'rows.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * A prepaid customer can't also owe (owner's rule, 2026-10: money paid
     * ahead pays off arrears first) — the clerk nets the two from the paper
     * record and enters whichever is left.
     *
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('rows', []) as $index => $row) {
                    if ((float) ($row['arrears'] ?? 0) > 0 && (int) ($row['advance_months'] ?? 0) > 0) {
                        $validator->errors()->add(
                            "rows.{$index}.advance_months",
                            'A customer who owes can\'t also be prepaid for months — deduct the arrears from what they paid ahead and enter only what remains.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return list<array{customer_uuid: string, arrears: ?string, advance_amount: ?string, advance_months: ?int, note: ?string}>
     */
    public function rows(): array
    {
        return array_map(static fn (array $row): array => [
            'customer_uuid' => (string) $row['customer_uuid'],
            'arrears' => isset($row['arrears']) ? (string) $row['arrears'] : null,
            'advance_amount' => isset($row['advance_amount']) ? (string) $row['advance_amount'] : null,
            'advance_months' => isset($row['advance_months']) ? (int) $row['advance_months'] : null,
            'note' => isset($row['note']) && trim((string) $row['note']) !== '' ? trim((string) $row['note']) : null,
        ], $this->validated('rows'));
    }
}
