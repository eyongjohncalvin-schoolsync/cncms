<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Expenditure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenditureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Expenditure::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category_uuid' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'description' => ['nullable', 'string'],
            'spent_at' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string'],
            'recorded_offline' => ['nullable', 'boolean'],
            'recorded_by_device' => ['nullable', 'string', 'max:255'],
            // Where the money came from and who was paid.
            'payment_method' => ['nullable', 'string', Rule::in(Expenditure::PAYMENT_METHODS)],
            'payment_ref' => ['nullable', 'string', 'max:60', 'required_if:payment_method,mtn_momo,orange_money'],
            'supplier_uuid' => ['nullable', 'uuid'],
            'service_uuid' => ['nullable', 'uuid'],
            'reference_no' => ['nullable', 'string', 'max:60'],
            // The receipt photo (or PDF), plus how it was captured.
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:6144'],
            'receipt_captured_at' => ['nullable', 'date'],
            'receipt_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'receipt_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'receipt_source' => ['nullable', 'string', 'in:camera,gallery,upload'],
        ];
    }

    /**
     * Capture details for the receipt photo, in the shape
     * ExpenditureService::create() stores.
     *
     * @return array<string, mixed>
     */
    public function receiptMeta(): array
    {
        return [
            'captured_at' => $this->input('receipt_captured_at'),
            'latitude' => $this->input('receipt_latitude'),
            'longitude' => $this->input('receipt_longitude'),
            'source' => $this->input('receipt_source', 'upload'),
        ];
    }
}
