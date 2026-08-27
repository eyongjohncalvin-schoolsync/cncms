<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Company::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'location' => ['required', 'string', 'max:30'],
            'head_office' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:50'],
            'phone' => ['required', 'string', 'max:30'],
            'tech_number' => ['nullable', 'string', 'max:30'],
            'momo_number' => ['nullable', 'string', 'max:30'],
            'momo_name' => ['nullable', 'string', 'max:50'],
            'reconnection_fine' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'arrears_second_approval_threshold' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'rccm_number' => ['nullable', 'string', 'max:40'],
            'niu' => ['nullable', 'string', 'max:20'],
            // Optional on every save — the same PATCH request that saves the
            // text fields can also carry a new logo file (Inertia's <Form>
            // switches to multipart/form-data automatically once a file
            // input is present). See PaymentController::uploadReceipt() for
            // the same image-upload validation shape used elsewhere.
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
        ];
    }
}
