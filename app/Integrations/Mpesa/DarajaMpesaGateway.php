<?php

namespace App\Integrations\Mpesa;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Safaricom Daraja (MPESA_DRIVER=daraja). DARAJA_ENVIRONMENT=sandbox uses
 * Safaricom's test environment; production moves real money.
 */
class DarajaMpesaGateway implements MpesaGateway
{
    /**
     * @param  array<string, mixed>  $config  config('travel.mpesa')
     */
    public function __construct(private array $config) {}

    public function isSimulated(): bool
    {
        return false;
    }

    public function baseUrl(): string
    {
        return ($this->config['environment'] ?? 'sandbox') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    public function stkPush(string $phone254, int $amount, string $accountReference, string $description): StkPushResult
    {
        $timestamp = $this->timestamp();
        $isTill = ($this->config['shortcode_type'] ?? 'paybill') === 'till';

        $response = $this->send('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $this->shortcode(),
            'Password' => $this->password($timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => $isTill ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone254,
            'PartyB' => $isTill ? (string) ($this->config['till_number'] ?? $this->shortcode()) : $this->shortcode(),
            'PhoneNumber' => $phone254,
            'CallBackURL' => CallbackUrls::stk(),
            'AccountReference' => mb_substr($accountReference, 0, 12),
            'TransactionDesc' => mb_substr($description, 0, 13),
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || (string) ($body['ResponseCode'] ?? '') !== '0') {
            throw new MpesaException('M-Pesa did not send the prompt: '.($body['errorMessage'] ?? $body['ResponseDescription'] ?? 'HTTP '.$response->status()).'.');
        }

        return new StkPushResult(
            merchantRequestId: (string) $body['MerchantRequestID'],
            checkoutRequestId: (string) $body['CheckoutRequestID'],
            responseCode: (string) $body['ResponseCode'],
            customerMessage: (string) ($body['CustomerMessage'] ?? ''),
        );
    }

    public function stkQuery(string $checkoutRequestId): StkQueryResult
    {
        $timestamp = $this->timestamp();

        $response = $this->send('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => $this->shortcode(),
            'Password' => $this->password($timestamp),
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]);

        $body = $response->json() ?? [];

        // "The transaction is being processed" comes back as an error response.
        if (($body['errorCode'] ?? null) === '500.001.1001') {
            return new StkQueryResult(processing: true, resultCode: null, resultDescription: $body['errorMessage'] ?? null, raw: $body);
        }

        if (! array_key_exists('ResultCode', $body)) {
            throw new MpesaException('M-Pesa status query failed: '.($body['errorMessage'] ?? 'HTTP '.$response->status()).'.');
        }

        return new StkQueryResult(
            processing: false,
            resultCode: (int) $body['ResultCode'],
            resultDescription: $body['ResultDesc'] ?? null,
            raw: $body,
        );
    }

    public function b2cPayout(string $phone254, int $amount, string $originatorConversationId, string $remarks): B2cPayoutResult
    {
        $b2c = (array) ($this->config['b2c'] ?? []);

        if (blank($b2c['initiator_name'] ?? null) || blank($b2c['shortcode'] ?? null)) {
            throw new MpesaException('M-Pesa payouts are not configured: set DARAJA_B2C_INITIATOR and DARAJA_B2C_SHORTCODE.');
        }

        $response = $this->send('/mpesa/b2c/v3/paymentrequest', [
            'OriginatorConversationID' => $originatorConversationId,
            'InitiatorName' => (string) $b2c['initiator_name'],
            'SecurityCredential' => $this->securityCredential(),
            'CommandID' => 'BusinessPayment',
            'Amount' => $amount,
            'PartyA' => (string) $b2c['shortcode'],
            'PartyB' => $phone254,
            'Remarks' => mb_substr($remarks, 0, 100),
            'QueueTimeOutURL' => CallbackUrls::b2cTimeout(),
            'ResultURL' => CallbackUrls::b2cResult(),
            'Occassion' => '',
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || (string) ($body['ResponseCode'] ?? '') !== '0') {
            throw new MpesaException('M-Pesa did not accept the payout: '.($body['errorMessage'] ?? $body['ResponseDescription'] ?? 'HTTP '.$response->status()).'.');
        }

        return new B2cPayoutResult(
            conversationId: (string) ($body['ConversationID'] ?? ''),
            originatorConversationId: (string) ($body['OriginatorConversationID'] ?? $originatorConversationId),
            responseCode: (string) $body['ResponseCode'],
            description: (string) ($body['ResponseDescription'] ?? ''),
        );
    }

    /**
     * The initiator password encrypted with Safaricom's public certificate
     * (RSA, PKCS1 padding, base64), or DARAJA_B2C_SECURITY_CREDENTIAL when
     * it was generated ahead of time.
     */
    public function securityCredential(): string
    {
        $b2c = (array) ($this->config['b2c'] ?? []);

        if (filled($b2c['security_credential'] ?? null)) {
            return (string) $b2c['security_credential'];
        }

        $path = (string) ($b2c['certificate_path'] ?? '');

        if (blank($b2c['initiator_password'] ?? null) || $path === '' || ! is_file($path)) {
            throw new MpesaException('M-Pesa payouts are not configured: set DARAJA_B2C_SECURITY_CREDENTIAL, or DARAJA_B2C_INITIATOR_PASSWORD with DARAJA_B2C_CERTIFICATE_PATH.');
        }

        $certificate = openssl_x509_read((string) file_get_contents($path));

        if ($certificate === false || ! openssl_public_encrypt((string) $b2c['initiator_password'], $encrypted, $certificate, OPENSSL_PKCS1_PADDING)) {
            throw new MpesaException('M-Pesa payouts could not encrypt the initiator password with the certificate at DARAJA_B2C_CERTIFICATE_PATH.');
        }

        return base64_encode($encrypted);
    }

    public function registerC2bUrls(): array
    {
        $response = $this->send('/mpesa/c2b/v1/registerurl', [
            'ShortCode' => $this->shortcode(),
            'ResponseType' => 'Completed',
            'ConfirmationURL' => CallbackUrls::c2bConfirmation(),
            'ValidationURL' => CallbackUrls::c2bValidation(),
        ]);

        if (! $response->successful()) {
            throw new MpesaException('M-Pesa refused the URL registration: '.($response->json('errorMessage') ?? 'HTTP '.$response->status()).'.');
        }

        return $response->json() ?? [];
    }

    /**
     * base64(shortcode + passkey + timestamp), as Daraja requires.
     */
    public function password(string $timestamp): string
    {
        return base64_encode($this->shortcode().($this->config['passkey'] ?? '').$timestamp);
    }

    /**
     * YmdHis in Nairobi time.
     */
    public function timestamp(): string
    {
        return CarbonImmutable::now('Africa/Nairobi')->format('YmdHis');
    }

    /**
     * OAuth access token, cached until shortly before it expires.
     */
    public function accessToken(): string
    {
        $key = 'travel.mpesa.token.'.($this->config['environment'] ?? 'sandbox').'.'.md5((string) ($this->config['consumer_key'] ?? ''));

        if ($token = Cache::get($key)) {
            return $token;
        }

        if (blank($this->config['consumer_key'] ?? null) || blank($this->config['consumer_secret'] ?? null)) {
            throw new MpesaException('M-Pesa is not configured: set DARAJA_CONSUMER_KEY and DARAJA_CONSUMER_SECRET.');
        }

        try {
            $response = Http::withBasicAuth((string) $this->config['consumer_key'], (string) $this->config['consumer_secret'])
                ->timeout(20)
                ->acceptJson()
                ->get($this->baseUrl().'/oauth/v1/generate', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new MpesaException('Could not reach M-Pesa. Try again in a moment.');
        }

        $token = $response->json('access_token');

        if (! $response->successful() || blank($token)) {
            throw new MpesaException('M-Pesa refused the credentials (HTTP '.$response->status().'). Check DARAJA_CONSUMER_KEY and DARAJA_CONSUMER_SECRET.');
        }

        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3599) - 60));

        return $token;
    }

    private function shortcode(): string
    {
        return (string) ($this->config['shortcode'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $path, array $payload): Response
    {
        try {
            return $this->client()->post($this->baseUrl().$path, $payload);
        } catch (ConnectionException) {
            throw new MpesaException('Could not reach M-Pesa. Try again in a moment.');
        }
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->accessToken())->timeout(30)->acceptJson()->asJson();
    }
}
