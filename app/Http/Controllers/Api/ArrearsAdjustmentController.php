<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\ArrearsAdjustmentData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreArrearsAdjustmentRequest;
use App\Http\Resources\ArrearsAdjustmentResource;
use App\Services\ArrearsAdjustmentService;
use Illuminate\Http\JsonResponse;

/**
 * JSON API counterpart of the web Arrears Adjustment REQUEST action
 * (App\Http\Controllers\ArrearsAdjustmentController::store()) — same
 * StoreArrearsAdjustmentRequest / ArrearsAdjustmentPolicy /
 * ArrearsAdjustmentService::create(), just returning JSON instead of an
 * Inertia redirect. Mirrors Api\ComplaintController's shape exactly (same
 * "JSON API counterpart of the web controller" doc-comment convention).
 *
 * Deliberately store() ONLY — no approve()/reject() JSON actions here. The
 * two-approver maker-checker review workflow stays office/web-only (see
 * references/arrears-adjustment.md and mobile-app-react-native.md's dated
 * addendum on this build): mobile lets any role REQUEST an adjustment
 * (ArrearsAdjustmentPolicy::create() is ungated for all 5 roles, confirmed
 * unchanged), matching the same "mobile creates, web reviews" split already
 * established in this app for payments/expenditures/complaints/
 * disconnections — there is no dedicated verify/reject approval UI on
 * mobile for any of those either.
 */
class ArrearsAdjustmentController extends Controller
{
    public function __construct(
        private readonly ArrearsAdjustmentService $adjustments,
    ) {}

    public function store(StoreArrearsAdjustmentRequest $request): JsonResponse
    {
        $adjustment = $this->adjustments->create(
            ArrearsAdjustmentData::fromArray($request->validated()),
            $request->user()->id,
        );

        return (new ArrearsAdjustmentResource($adjustment))->response()->setStatusCode(201);
    }
}
