<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Services\FieldDeviceService;
use App\Services\ReceiptStockService;
use App\Support\PaymentReceiptLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * What the mobile app needs to issue receipts in the field
 * (docs/plans/cash-controls.md, waves 2–3):
 *
 *  - register  — once per phone: returns the device uuid and its signing
 *    secret. The secret is shown exactly once; the app keeps it in the
 *    phone's secure store.
 *  - stock     — on every sync: the unused receipt numbers this phone may
 *    issue (topped up automatically when running low), plus the base of
 *    the check link the phone prints as a QR.
 *  - cancel    — a number the phone burned without a payment (printer jam).
 *
 * The phone identifies itself with the `X-Field-Device` header (its uuid).
 */
class FieldReceiptController extends Controller
{
    public function __construct(
        private readonly FieldDeviceService $devices,
        private readonly ReceiptStockService $stock,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $this->authorize('issueOwn', ReceiptBlock::class);

        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        ['device' => $device, 'secret' => $secret] = $this->devices->register($request->user(), $validated['name']);

        return response()->json(['data' => [
            'device_uuid' => $device->uuid,
            'name' => $device->name,
            'secret' => $secret,
            'check_url_base' => $this->checkUrlBase(),
        ]], 201);
    }

    public function stock(Request $request): JsonResponse
    {
        $this->authorize('issueOwn', ReceiptBlock::class);

        $device = $this->devices->activeFor($request->user(), $request->header('X-Field-Device'));

        if ($device === null) {
            return response()->json([
                'message' => 'This phone is not registered for receipts, or it has been revoked. Register it again.',
                'code' => 'device_not_registered',
            ], 409);
        }

        $numbers = $this->stock->digitalStockFor($device);

        return response()->json(['data' => [
            'device_uuid' => $device->uuid,
            'numbers' => $numbers->pluck('number')->values()->all(),
            'check_url_base' => $this->checkUrlBase(),
        ]]);
    }

    public function cancel(Request $request, string $number): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $leaf = ReceiptLeaf::query()->with('block')->where('number', strtoupper($number))->firstOrFail();

        $this->authorize('cancelLeaf', $leaf->block);

        try {
            $leaf = $this->stock->cancelLeaf($leaf, $validated['reason'], $request->user());
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        return response()->json(['data' => ['number' => $leaf->number, 'status' => $leaf->status]]);
    }

    /**
     * "https://…/r/{tenant}/" — the phone appends "{number}?c={code}" to
     * build the same link PaymentReceiptLink::check() does, offline.
     */
    private function checkUrlBase(): string
    {
        return rtrim(PaymentReceiptLink::checkFor('X'), 'X');
    }
}
