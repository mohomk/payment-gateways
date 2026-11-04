<?php

namespace Moho\PaymentGateways\Tests\Nlb;

use InvalidArgumentException;
use Moho\PaymentGateways\Gateways;
use Moho\PaymentGateways\InvalidCallbackException;
use Moho\PaymentGateways\Nlb\NlbGateway;
use Moho\PaymentGateways\PaymentRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NlbGatewayTest extends TestCase
{
    private const CONFIG = [
        'api_key' => 'key-1',
        'username' => 'shop',
        'password' => 'pass',
        'shared_secret' => 'secret',
    ];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $sent = [];

    /**
     * @param  array<string, mixed>  $response
     */
    private function gateway(array $response, bool $testMode = false): NlbGateway
    {
        return new NlbGateway(self::CONFIG, $testMode, function (string $method, string $url, array $headers, string $body) use ($response): string {
            $this->sent[] = compact('method', 'url', 'headers', 'body');

            return json_encode($response);
        });
    }

    private function request(): PaymentRequest
    {
        return new PaymentRequest(
            paymentId: 'ORD-42-1',
            amount: 1990,
            currency: 'MKD',
            successUrl: 'https://shop.test/pay/abc/success',
            failUrl: 'https://shop.test/pay/abc/fail',
            fee: 30,
            description: 'ORD-42',
            firstName: 'Ана',
            email: 'a@b.mk',
        );
    }

    public function test_checkout_sends_a_signed_debit_and_returns_the_payment_page_as_a_get_form(): void
    {
        $form = $this->gateway(['success' => true, 'uuid' => 'u-1', 'returnType' => 'REDIRECT', 'redirectUrl' => 'https://pay.test/hpp/u-1?lang=mk&t=x'])
            ->checkout($this->request());

        $this->assertSame('https://pay.test/hpp/u-1', $form->url);
        $this->assertSame(['lang' => 'mk', 't' => 'x'], $form->fields);
        $this->assertSame('GET', $form->method);

        ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body] = $this->sent[0];
        $this->assertSame('POST', $method);
        $this->assertSame(NlbGateway::URL_LIVE . '/api/v3/transaction/key-1/debit', $url);
        $this->assertSame('Basic ' . base64_encode('shop:pass'), $headers['Authorization']);
        $this->assertSame(NlbGateway::signature('POST', $body, $headers['Content-Type'], $headers['Date'], '/api/v3/transaction/key-1/debit', 'secret'), $headers['X-Signature']);

        $payload = json_decode($body, true);
        $this->assertSame('ORD-42-1', $payload['merchantTransactionId']);
        $this->assertSame('2020.00', $payload['amount']);
        $this->assertSame('MKD', $payload['currency']);
        $this->assertSame('https://shop.test/pay/abc/success?merchantTransactionId=ORD-42-1', $payload['successUrl']);
        $this->assertSame($payload['successUrl'], $payload['callbackUrl']);
        $this->assertSame('https://shop.test/pay/abc/fail', $payload['cancelUrl']);
        $this->assertSame(['firstName' => 'Ана', 'email' => 'a@b.mk'], $payload['customer']);
    }

    public function test_test_mode_uses_the_sandbox(): void
    {
        $this->gateway(['success' => true, 'returnType' => 'REDIRECT', 'redirectUrl' => 'https://pay.test/hpp'], testMode: true)->checkout($this->request());

        $this->assertStringStartsWith(NlbGateway::URL_TEST . '/', $this->sent[0]['url']);
    }

    public function test_checkout_throws_when_bankart_refuses(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid currency');

        $this->gateway(['success' => false, 'errors' => [['message' => 'Invalid currency']]])->checkout($this->request());
    }

    public function test_callback_is_confirmed_with_bankarts_status(): void
    {
        $result = $this->gateway(['success' => true, 'transactionStatus' => 'SUCCESS', 'transactionType' => 'DEBIT', 'merchantTransactionId' => 'ORD-42-1', 'uuid' => 'u-1', 'amount' => '2020.00', 'currency' => 'MKD'])
            ->handleCallback(['merchantTransactionId' => 'ORD-42-1', 'transactionStatus' => 'ignored']);

        $this->assertSame(Gateways::NLB, $result->provider);
        $this->assertSame('ORD-42-1', $result->paymentId);
        $this->assertSame(2020, $result->amount);
        $this->assertSame('MKD', $result->currency);
        $this->assertSame('u-1', $result->reference);

        ['method' => $method, 'url' => $url, 'headers' => $headers] = $this->sent[0];
        $this->assertSame('GET', $method);
        $this->assertSame(NlbGateway::URL_LIVE . '/api/v3/status/key-1/getByMerchantTransactionId/ORD-42-1', $url);
        $this->assertArrayNotHasKey('Content-Type', $headers);
        $this->assertSame(NlbGateway::signature('GET', '', '', $headers['Date'], '/api/v3/status/key-1/getByMerchantTransactionId/ORD-42-1', 'secret'), $headers['X-Signature']);
    }

    public function test_status_values_are_read_in_any_case(): void
    {
        $result = $this->gateway(['success' => true, 'transactionStatus' => 'success', 'transactionType' => 'debit', 'merchantTransactionId' => 'ORD-42-1', 'amount' => '2020.00', 'currency' => 'MKD'])
            ->handleCallback(['merchantTransactionId' => 'ORD-42-1']);

        $this->assertSame(2020, $result->amount);
    }

    public function test_a_fractional_amount_never_matches(): void
    {
        $result = $this->gateway(['success' => true, 'transactionStatus' => 'SUCCESS', 'transactionType' => 'DEBIT', 'merchantTransactionId' => 'ORD-42-1', 'amount' => '2019.60', 'currency' => 'MKD'])
            ->handleCallback(['merchantTransactionId' => 'ORD-42-1']);

        $this->assertSame(-1, $result->amount);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unsuccessfulStatuses(): array
    {
        $paid = ['success' => true, 'transactionStatus' => 'SUCCESS', 'transactionType' => 'DEBIT', 'merchantTransactionId' => 'ORD-42-1', 'amount' => '2020.00'];

        return [
            'error' => [[...$paid, 'transactionStatus' => 'ERROR']],
            'pending' => [[...$paid, 'transactionStatus' => 'PENDING']],
            'not a debit' => [[...$paid, 'transactionType' => 'REFUND']],
            'another payment' => [[...$paid, 'merchantTransactionId' => 'ORD-99-1']],
            'lookup failed' => [['success' => false]],
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsuccessfulStatuses')]
    public function test_callback_throws_unless_bankart_says_paid(array $status): void
    {
        $this->expectException(InvalidCallbackException::class);

        $this->gateway($status)->handleCallback(['merchantTransactionId' => 'ORD-42-1']);
    }

    public function test_callback_without_a_payment_id_never_calls_bankart(): void
    {
        try {
            $this->gateway([])->handleCallback([]);
            $this->fail('Expected InvalidCallbackException.');
        } catch (InvalidCallbackException) {
            $this->assertSame([], $this->sent);
        }
    }

    public function test_config_must_be_complete(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Gateways::make(Gateways::NLB, [...self::CONFIG, 'shared_secret' => ''], testMode: true);
    }

    public function test_signature_is_base64_hmac_sha512_of_the_request_parts(): void
    {
        $expected = base64_encode(hash_hmac('sha512', "POST\n" . hash('sha512', '{}') . "\napplication/json\nThu, 05 Nov 2026 10:00:00 GMT\n/api/v3/x", 'secret', true));

        $this->assertSame($expected, NlbGateway::signature('POST', '{}', 'application/json', 'Thu, 05 Nov 2026 10:00:00 GMT', '/api/v3/x', 'secret'));
    }
}
