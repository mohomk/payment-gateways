<?php

namespace Moho\PaymentGateways\Nlb;

use Closure;
use InvalidArgumentException;
use Moho\PaymentGateways\Gateways;
use Moho\PaymentGateways\InvalidCallbackException;
use Moho\PaymentGateways\PaymentGateway;
use Moho\PaymentGateways\PaymentRequest;
use Moho\PaymentGateways\PaymentResult;
use Moho\PaymentGateways\RedirectForm;
use RuntimeException;

/**
 * NLB card payments on the Bankart platform (API v3), hosted payment page.
 *
 * Unlike cPay, checkout is a server-to-server call that returns the payment page URL, and neither the
 * shopper's return nor the callback is trusted: handleCallback() asks Bankart for the transaction's status.
 *
 * Config keys: api_key, username, password, shared_secret.
 */
final class NlbGateway implements PaymentGateway
{
    public const URL_LIVE = 'https://gateway.bankart.si';

    public const URL_TEST = 'https://bankart.paymentsandbox.cloud';

    /** The return/callback query parameter carrying our payment id, which Bankart doesn't add itself. */
    public const PAYMENT_ID_PARAM = 'merchantTransactionId';

    /** @var Closure(string, string, array<string, string>, string): string */
    private readonly Closure $send;

    /**
     * @param  array<string, mixed>  $config
     * @param  ?Closure(string $method, string $url, array<string, string> $headers, string $body): string  $send  the HTTP client, returning the response body (tests fake it)
     */
    public function __construct(private readonly array $config, private readonly bool $testMode, ?Closure $send = null)
    {
        foreach (['api_key', 'username', 'password', 'shared_secret'] as $key) {
            if (($config[$key] ?? '') === '') {
                throw new InvalidArgumentException("NLB (Bankart) config is missing [{$key}].");
            }
        }

        $this->send = $send ?? self::httpSend(...);
    }

    public function checkout(PaymentRequest $request): RedirectForm
    {
        $successUrl = self::withPaymentId($request->successUrl, $request->paymentId);

        $response = $this->call('POST', '/api/v3/transaction/' . $this->config['api_key'] . '/debit', array_filter([
            'merchantTransactionId' => $request->paymentId,
            'amount' => number_format($request->total(), 2, '.', ''),
            'currency' => $request->currency,
            'successUrl' => $successUrl,
            'cancelUrl' => $request->failUrl,
            'errorUrl' => $request->failUrl,
            // Bankart posts the result here too, so the payment is recorded even if the shopper never comes back.
            'callbackUrl' => $successUrl,
            'description' => $request->description,
            'customer' => array_filter([
                'firstName' => $request->firstName,
                'lastName' => $request->lastName,
                'email' => $request->email,
                'billingAddress1' => $request->address,
                'billingCity' => $request->city,
                'billingPostcode' => $request->zip,
                'billingPhone' => $request->phone,
            ], fn(?string $value): bool => (string) $value !== ''),
        ], fn(mixed $value): bool => $value !== null && $value !== []));

        if (!($response['success'] ?? false) || ($response['returnType'] ?? null) !== 'REDIRECT' || empty($response['redirectUrl'])) {
            throw new RuntimeException('NLB (Bankart) did not start the payment: ' . ($response['errors'][0]['message'] ?? $response['returnType'] ?? 'no response') . '.');
        }

        // A GET form drops the action's query string, so its parameters go in as fields.
        $url = (string) $response['redirectUrl'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $fields);

        return new RedirectForm(strtok($url, '?'), array_map('strval', $fields), 'GET');
    }

    /**
     * Reads the payment id from the shopper's return or Bankart's callback, then asks Bankart for its status.
     *
     * @throws InvalidCallbackException when there's no payment id or the payment didn't succeed
     */
    public function handleCallback(array $callback): PaymentResult
    {
        $paymentId = (string) ($callback[self::PAYMENT_ID_PARAM] ?? '');

        if ($paymentId === '') {
            throw new InvalidCallbackException('NLB (Bankart) callback has no payment id.');
        }

        $status = $this->call('GET', '/api/v3/status/' . $this->config['api_key'] . '/getByMerchantTransactionId/' . rawurlencode($paymentId));

        // Bankart's docs show both "DEBIT" and "debit".
        if (!($status['success'] ?? false) || strtoupper((string) ($status['transactionStatus'] ?? '')) !== 'SUCCESS' || strtoupper((string) ($status['transactionType'] ?? '')) !== 'DEBIT'
            || ($status['merchantTransactionId'] ?? null) !== $paymentId) {
            throw new InvalidCallbackException("NLB (Bankart) payment {$paymentId} is not successful.");
        }

        $cents = (int) round((float) ($status['amount'] ?? 0) * 100);

        return new PaymentResult(
            provider: Gateways::NLB,
            paymentId: $paymentId,
            // A fractional amount can't be one we asked for; -1 makes the app's amount check fail.
            amount: $cents % 100 === 0 ? intdiv($cents, 100) : -1,
            currency: (string) ($status['currency'] ?? ''),
            reference: isset($status['uuid']) ? (string) $status['uuid'] : null,
            raw: $status,
        );
    }

    /**
     * Bankart's X-Signature: base64 HMAC-SHA512 over method, sha512(body), content type, date and URI.
     */
    public static function signature(string $method, string $body, string $contentType, string $date, string $uri, string $secret): string
    {
        return base64_encode(hash_hmac('sha512', implode("\n", [$method, hash('sha512', $body), $contentType, $date, $uri]), $secret, true));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, string $uri, ?array $payload = null): array
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $contentType = $payload === null ? '' : 'application/json; charset=utf-8';
        $date = gmdate('D, d M Y H:i:s \G\M\T');

        $headers = array_filter([
            'Authorization' => 'Basic ' . base64_encode($this->config['username'] . ':' . $this->config['password']),
            'Date' => $date,
            'Content-Type' => $contentType,
            'Accept' => 'application/json',
            'X-Signature' => self::signature($method, $body, $contentType, $date, $uri, (string) $this->config['shared_secret']),
        ]);

        $response = json_decode(($this->send)($method, ($this->testMode ? self::URL_TEST : self::URL_LIVE) . $uri, $headers, $body), true);

        return is_array($response) ? $response : [];
    }

    private static function withPaymentId(string $url, string $paymentId): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query([self::PAYMENT_ID_PARAM => $paymentId]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function httpSend(string $method, string $url, array $headers, string $body): string
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", array_map(fn(string $name, string $value): string => "{$name}: {$value}", array_keys($headers), $headers)),
            'content' => $body,
            'timeout' => 30,
            // Bankart answers errors with a JSON body too.
            'ignore_errors' => true,
        ]]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException("NLB (Bankart) is unreachable ({$method} {$url}).");
        }

        return $response;
    }
}
