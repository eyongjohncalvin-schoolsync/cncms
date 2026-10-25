<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\ArrearsAdjustmentData;
use App\Http\Requests\RejectFreeMonthGrantRequest;
use App\Http\Requests\StoreFreeMonthGrantRequest;
use App\Models\Customer;
use App\Models\FreeMonthGrant;
use App\Models\Service;
use App\Services\ArrearsAdjustmentService;
use App\Services\FreeMonthGrantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Free months on the customer page (docs/plans/installation-and-promotions.md,
 * phase 2): ask for compensation free months for poor service (D12) —
 * alone, alongside an arrears write-off, or on top of paid months — and
 * approve / reject any request waiting (compensation, or installation months
 * beyond the offer). App\Services\FreeMonthGrantService does the work.
 */
class FreeMonthGrantController extends Controller
{
    public function __construct(
        private readonly FreeMonthGrantService $grants,
    ) {}

    public function store(StoreFreeMonthGrantRequest $request, Customer $customer): RedirectResponse
    {
        $validated = $request->validated();

        $grants = DB::transaction(function () use ($validated, $customer, $request) {
            $adjustmentId = null;

            if (! empty($validated['write_off_amount'])) {
                $adjustmentId = app(ArrearsAdjustmentService::class)->create(new ArrearsAdjustmentData(
                    customerUuid: $customer->uuid,
                    targetPeriod: Carbon::now()->format('Y-m'),
                    direction: 'decrease',
                    amount: number_format((float) $validated['write_off_amount'], 2, '.', ''),
                    reasonCategory: 'goodwill_service_outage',
                    reasonNote: $validated['reason'],
                ), $request->user()->id)->id;
            }

            $serviceIds = isset($validated['service_uuids']) && $validated['service_uuids'] !== []
                ? Service::query()->whereIn('uuid', $validated['service_uuids'])->pluck('id')->map(fn ($id): int => (int) $id)->all()
                : null;

            return $this->grants->requestCompensation($customer, $serviceIds, (int) $validated['months'], $validated['reason'], $request->user(), $adjustmentId);
        });

        $writeOff = ! empty($validated['write_off_amount']) ? ' The arrears write-off waits for approval.' : '';

        if ($grants->first()?->status === FreeMonthGrant::STATUS_APPROVED) {
            return back()->with('success', 'Free months given — from '.Carbon::createFromFormat('!Y-m', (string) $grants->min('start_period'))->format('M Y').'.'.$writeOff);
        }

        return back()->with('success', 'Free months requested. Someone else must approve them'.(! empty($validated['write_off_amount']) ? ' (and the arrears write-off).' : '.'));
    }

    public function approve(Request $request, FreeMonthGrant $freeMonthGrant): RedirectResponse
    {
        $this->authorize('approve', $freeMonthGrant);

        $grants = $this->grants->approveGroup($freeMonthGrant->group_uuid, $request->user());
        $started = $grants->filter(fn (FreeMonthGrant $grant): bool => $grant->start_period !== null);

        return back()->with('success', $started->isNotEmpty()
            ? 'Free months approved — from '.Carbon::createFromFormat('!Y-m', (string) $started->min('start_period'))->format('M Y').'.'
            : 'Free months approved — they start the month after the installation is fully paid.');
    }

    public function reject(RejectFreeMonthGrantRequest $request, FreeMonthGrant $freeMonthGrant): RedirectResponse
    {
        $this->grants->rejectGroup($freeMonthGrant->group_uuid, $request->user(), $request->validated('reason'));

        return back()->with('success', 'Free months rejected.');
    }
}
