<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Zone;
use App\Rules\UniqueUpperCaseLabel;
use App\Support\TenantContext;
use App\Support\UpperCaseLabel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Zone::class);
    }

    /**
     * Also used as-is by ZoneImportService, which normalises the name itself
     * before validating (it never goes through prepareForValidation()).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => self::nameRules(),
            // Branches v2: a fenced manager's zones are always in their own branch.
            'branch_uuid' => ['nullable', 'uuid', 'exists:branches,uuid', Rule::when(fn (): bool => app(TenantContext::class)->isFenced(), ['missing'])],
        ];
    }

    /**
     * The rules one new zone name must pass, judged on the value after
     * UpperCaseLabel::normalise(). Shared with StoreBranchZonesRequest (the
     * branch setup page's quick-add) so a batch judges every name exactly
     * like a single create.
     *
     * @return array<int, mixed>
     */
    public static function nameRules(): array
    {
        return ['required', 'string', 'max:25', new UniqueUpperCaseLabel('zones')];
    }

    /**
     * Normalised before validation so max:25 and the unique check judge the
     * value that will be stored ("thr01" is rejected when "THR01" exists).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => UpperCaseLabel::normalise($this->input('name'))]);
        }
    }
}
