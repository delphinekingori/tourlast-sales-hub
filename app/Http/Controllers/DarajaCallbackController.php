<?php

namespace App\Http\Controllers;

use App\Actions\Travel\Payments\ReceiveDarajaCallback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Public endpoints Safaricom Daraja posts to. Safaricom cannot send a token,
 * so each URL carries DARAJA_CALLBACK_SECRET; an optional IP allowlist
 * (DARAJA_ALLOWED_IPS) narrows it further.
 */
class DarajaCallbackController extends Controller
{
    public function __construct(private ReceiveDarajaCallback $receive) {}

    public function stk(Request $request, string $secret): JsonResponse
    {
        return $this->receive($request, $secret, ReceiveDarajaCallback::Stk);
    }

    public function validation(Request $request, string $secret): JsonResponse
    {
        return $this->receive($request, $secret, ReceiveDarajaCallback::C2bValidation);
    }

    public function confirmation(Request $request, string $secret): JsonResponse
    {
        return $this->receive($request, $secret, ReceiveDarajaCallback::C2bConfirmation);
    }

    private function receive(Request $request, string $secret, string $type): JsonResponse
    {
        $this->guard($request, $secret);

        return response()->json($this->receive->handle($type, $request->json()->all() ?: $request->all(), $request->ip()));
    }

    private function guard(Request $request, string $secret): void
    {
        $expected = (string) config('travel.mpesa.callback_secret');

        if ($expected === '') {
            if (! app()->isLocal() && ! app()->runningUnitTests()) {
                Log::error('Daraja callback refused: DARAJA_CALLBACK_SECRET is not set.');

                throw new HttpException(503, 'M-Pesa callbacks are not configured.');
            }

            $expected = 'not-set';
        }

        abort_unless(hash_equals($expected, $secret), 404);

        $allowed = (array) config('travel.mpesa.allowed_ips', []);

        abort_if($allowed !== [] && ! in_array($request->ip(), $allowed, true), 403);
    }
}
