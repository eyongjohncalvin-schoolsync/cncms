<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Zone;
use Illuminate\Foundation\Http\FormRequest;

class StoreZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Zone::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:25', 'unique:zones,name'],
            'town' => ['nullable', 'string', 'max:25'],
        ];
    }
}
