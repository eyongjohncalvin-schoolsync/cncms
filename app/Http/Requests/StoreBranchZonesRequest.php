<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\Zone;
use App\Support\TenantContext;
use App\Support\UpperCaseLabel;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The branch setup page's quick-add: several new zones for one branch in a
 * single request. Every name passes the same rules as a single zone create
 * (StoreZoneRequest::nameRules()), judged after the same normalisation, and
 * a name repeated within the batch is refused on its second appearance —
 * errors are keyed `names.N` so the page can mark the exact chip.
 */
class StoreBranchZonesRequest extends FormRequest
{
    /** Most names one quick-add may submit. */
    public const int MAX_NAMES = 100;

    /**
     * Whoever may create a zone today (ZonePolicy::create()), and — exactly
     * like a single create, where a fenced user's zone always lands in their
     * own branch — a branch-fenced user only for their own branch.
     */
    public function authorize(): bool
    {
        if (! $this->user()->can('create', Zone::class)) {
            return false;
        }

        $context = app(TenantContext::class);

        /** @var Branch $branch */
        $branch = $this->route('branch');

        return ! $context->isFenced() || $context->branchId === $branch->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'names' => ['required', 'list', 'min:1', 'max:'.self::MAX_NAMES],
            'names.*' => [...StoreZoneRequest::nameRules(), $this->notRepeatedInBatch(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'names.required' => 'Add at least one zone name.',
            'names.*.required' => 'A zone name cannot be blank.',
            'names.*.max' => 'A zone name may not be longer than 25 characters.',
        ];
    }

    /**
     * Every name normalised before validation, so max:25, the unique check
     * and the in-batch duplicate check all judge the stored value ("thr01"
     * and "THR01" in one batch are the same zone).
     */
    protected function prepareForValidation(): void
    {
        $names = $this->input('names');

        if (is_array($names)) {
            $this->merge([
                'names' => array_map(fn (mixed $name): mixed => is_string($name) ? UpperCaseLabel::normalise($name) : $name, $names),
            ]);
        }
    }

    /**
     * Fails a name already present earlier in the batch; the first
     * occurrence stays valid, so only the repeat is marked.
     */
    private function notRepeatedInBatch(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $index = (int) substr($attribute, strlen('names.'));
        $earlier = array_slice((array) $this->input('names'), 0, $index);

        if (in_array($value, $earlier, true)) {
            $fail('This zone is already in the list.');
        }
    }
}
