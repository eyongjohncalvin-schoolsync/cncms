<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ExpenditureData;
use App\Models\Expenditure;
use App\Models\RecurringExpense;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Regular monthly costs. Each template produces at most one expense per
 * month (a unique index enforces it), recorded with one tap and then
 * treated like any other expense — the same approval limits apply.
 */
class RecurringExpenseService
{
    public function __construct(
        private readonly ExpenditureService $expenditures,
    ) {}

    /**
     * Every template with this month's status: recorded (and how it
     * stands) or still due.
     *
     * @return list<array<string, mixed>>
     */
    public function forPeriod(string $period): array
    {
        $templates = RecurringExpense::query()
            ->with(['category', 'supplier', 'service'])
            ->orderByDesc('is_active')
            ->orderBy('day_of_month')
            ->orderBy('name')
            ->get();

        $recorded = Expenditure::query()
            ->whereIn('recurring_expense_id', $templates->modelKeys())
            ->where('recurring_period', $period)
            ->get(['uuid', 'recurring_expense_id', 'status', 'amount'])
            ->keyBy('recurring_expense_id');

        return $templates->map(fn (RecurringExpense $template): array => [
            'uuid' => $template->uuid,
            'name' => $template->name,
            'amount' => (string) $template->amount,
            'payment_method' => $template->payment_method,
            'day_of_month' => $template->day_of_month,
            'is_active' => $template->is_active,
            'category_uuid' => $template->category?->uuid,
            'category_name' => $template->category?->name,
            'supplier_uuid' => $template->supplier?->uuid,
            'supplier_name' => $template->supplier?->name,
            'service_uuid' => $template->service?->uuid,
            'service_name' => $template->service?->name,
            'recorded' => ($expense = $recorded->get($template->id)) === null ? null : [
                'uuid' => $expense->uuid,
                'status' => $expense->status,
                'amount' => (string) $expense->amount,
            ],
        ])->values()->all();
    }

    /**
     * Records this month's expense for a template. $amount lets the
     * electricity bill differ from the usual figure.
     */
    public function record(RecurringExpense $template, string $period, User $actor, ?string $amount = null): Expenditure
    {
        if (! $template->is_active) {
            throw ValidationException::withMessages(['template' => ['This regular expense is switched off.']]);
        }

        $template->loadMissing(['category', 'supplier', 'service']);
        $month = Carbon::createFromFormat('!Y-m', $period);
        $spentAt = $month->copy()->day(min($template->day_of_month, $month->daysInMonth));

        if ($spentAt->isFuture()) {
            $spentAt = Carbon::today();
        }

        $already = Expenditure::query()->where('recurring_expense_id', $template->id)->where('recurring_period', $period)->exists();

        if ($already) {
            throw ValidationException::withMessages(['template' => ["{$template->name} is already recorded for {$month->format('F Y')}."]]);
        }

        try {
            return $this->expenditures->create(
                new ExpenditureData(
                    categoryUuid: $template->category->uuid,
                    amount: $amount ?? (string) $template->amount,
                    description: $template->name.' — '.$month->format('F Y'),
                    spentAt: $spentAt->toDateString(),
                    paymentMethod: $template->payment_method,
                    supplierUuid: $template->supplier?->uuid,
                    serviceUuid: $template->service?->uuid,
                ),
                $actor->id,
                attributes: ['recurring_expense_id' => $template->id, 'recurring_period' => $period],
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['template' => ["{$template->name} is already recorded for {$month->format('F Y')}."]]);
        }
    }
}
