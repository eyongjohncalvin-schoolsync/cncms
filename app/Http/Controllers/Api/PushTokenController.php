<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\PushTokenData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePushTokenRequest;
use App\Services\PushTokenService;
use Illuminate\Http\JsonResponse;

/**
 * Mobile push token registration — mobile-push-notifications build notes.
 * The mobile client calls this fire-and-forget right after login (and once
 * per cold-start for an already-cached session), never blocking the login
 * transition on it.
 */
class PushTokenController extends Controller
{
    public function __construct(
        private readonly PushTokenService $pushTokens,
    ) {}

    public function store(StorePushTokenRequest $request): JsonResponse
    {
        $token = $this->pushTokens->register($request->user(), PushTokenData::fromArray($request->validated()));

        return response()->json([
            'uuid' => $token->uuid,
            'registered_at' => $token->registered_at?->toIso8601String(),
        ], 201);
    }
}
