<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\IssueApiToken;
use App\Enums\ApiScope;
use App\Http\Resources\V1\MeResource;
use App\Http\Resources\V1\TokenResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sign in to get a token, list and revoke your own tokens.
 */
class AuthController extends ApiController
{
    /**
     * POST /auth/tokens — exchange email and password for a token.
     */
    public function store(Request $request, IssueApiToken $issueApiToken): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiScope::values())],
            'expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $user = User::query()->where('email', strtolower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => $user->inactiveMessage()]);
        }

        $token = $issueApiToken->handle(
            $user,
            $data['device_name'],
            $data['scopes'] ?? ApiScope::values(),
            isset($data['expires_in_days']) ? CarbonImmutable::now()->addDays((int) $data['expires_in_days']) : null,
        );

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'scopes' => $token->accessToken->scopes(),
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new MeResource($user->withAccessToken($token->accessToken)),
        ], 201);
    }

    /**
     * GET /auth/tokens — your tokens (all devices and apps).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TokenResource::collection($this->user($request)->tokens()->with('issuer:id,name')->latest()->get());
    }

    /**
     * DELETE /auth/tokens/current — sign out this token.
     */
    public function destroyCurrent(Request $request): JsonResponse
    {
        $this->user($request)->currentAccessToken()?->delete();

        return response()->json(['message' => 'Token revoked.']);
    }

    /**
     * DELETE /auth/tokens/{id} — revoke one of your tokens.
     */
    public function destroy(Request $request, int $token): JsonResponse
    {
        $this->user($request)->tokens()->whereKey($token)->firstOrFail()->delete();

        return response()->json(['message' => 'Token revoked.']);
    }
}
