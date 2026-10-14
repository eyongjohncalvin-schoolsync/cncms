<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Expenditure;
use App\Services\ExpenseControlService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One expense, for the API (mobile) and the web pages alike. The receipt
 * photo is private: `receipt_url` points at the authorised receipt route
 * of whichever side asked (session for web, token for the app).
 *
 * @mixin Expenditure
 */
class ExpenditureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isApi = $request->is('api/*');
        $user = fn (string $relation): ?array => $this->relationLoaded($relation) && $this->{$relation} !== null
            ? ['uuid' => $this->{$relation}->uuid, 'name' => $this->{$relation}->name]
            : null;

        return [
            'uuid' => $this->uuid,
            'category_uuid' => $this->whenLoaded('category', fn () => $this->category->uuid),
            'category_name' => $this->whenLoaded('category', fn () => $this->category->name),
            'amount' => (string) $this->amount,
            'description' => $this->description,
            'spent_at' => $this->spent_at?->toDateString(),
            'notes' => $this->notes,
            'status' => $this->status,
            'auto_approved' => (bool) $this->auto_approved,
            'flags' => collect($this->flags ?? [])->map(fn (string $flag): array => [
                'key' => $flag,
                'label' => ExpenseControlService::FLAG_LABELS[$flag] ?? $flag,
            ])->values()->all(),
            'payment_method' => $this->payment_method,
            'payment_ref' => $this->payment_ref,
            'reference_no' => $this->reference_no,
            'supplier' => $this->relationLoaded('supplier') && $this->supplier !== null
                ? ['uuid' => $this->supplier->uuid, 'name' => $this->supplier->name, 'status' => $this->supplier->status]
                : null,
            'service' => $this->relationLoaded('service') && $this->service !== null
                ? ['uuid' => $this->service->uuid, 'name' => $this->service->name]
                : null,
            'has_receipt' => $this->receipt_path !== null,
            'receipt_url' => $this->receipt_path === null ? null : route(
                $isApi ? 'api.resources.expenditures.receipt' : 'resources.expenditures.receipt',
                ['expenditure' => $this->uuid],
            ),
            'receipt_meta' => $this->receipt_meta,
            'recorded_offline' => (bool) $this->recorded_offline,
            'recorded_by' => $user('user'),
            'first_approved_by' => $user('firstApprover'),
            'first_approved_at' => $this->first_approved_at?->toIso8601String(),
            'approved_by' => $user('approver'),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_by' => $user('rejecter'),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'voided_by' => $user('voider'),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'recurring_period' => $this->recurring_period,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
