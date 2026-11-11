<?php

namespace Moho\PaymentGateways\Tests\Halkbank;

use InvalidArgumentException;
use Moho\PaymentGateways\Gateways;
use Moho\PaymentGateways\Halkbank\HalkbankGateway;
use Moho\PaymentGateways\InvalidCallbackException;
use Moho\PaymentGateways\PaymentRequest;
use PHPUnit\Framework\TestCase;

class HalkbankGatewayTest extends TestCase
{
    private const CONFIG = ['client_id' => '180000123', 'store_key' => 'SKEY|1\\', 'gateway_url' => 'https://bank.test/fim/est3Dgate'];

    public function test_hash_joins_values_in_case_insensitive_name_order_with_escaped_store_key(): void
    {
        // Order: amount, BillToName, clientid, oid (case ignored); HASH and encoding are skipped.
        $plain = '100.00|Ana|180000123|A\\|1|SKEY\\|1\\\\';

        $this->assertSame(
            base64_encode(hash('sha512', $plain, true)),
            HalkbankGateway::hash(['oid' => 'A|1', 'clientid' => '180000123', 'BillToName' => 'Ana', 'amount' => '100.00', 'HASH' => 'x', 'encoding' => 'utf-8'], 'SKEY|1\\'),
        );
    }

    public function test_checkout_builds_a_signed_form_in_test_and_live_mode(): void
    {
        $request = new PaymentRequest(
            paymentId: 'ORD-42-1',
            amount: 1990,
            currency: 'MKD',
            successUrl: 'https://shop.test/ok',
            failUrl: 'https://shop.test/fail',
            fee: 30,
            firstName: 'Ана',
            lastName: 'Петрова',
        );

        $form = Gateways::make(Gateways::HALKBANK, self::CONFIG, testMode: false)->checkout($request);

        $this->assertSame('https://bank.test/fim/est3Dgate', $form->url);
        $this->assertSame('2020.00', $form->fields['amount']);
        $this->assertSame('807', $form->fields['currency']);
        $this->assertSame('ORD-42-1', $form->fields['oid']);
        $this->assertSame('Ана Петрова', $form->fields['BillToName']);
        $this->assertArrayNotHasKey('email', $form->fields);
        $this->assertSame(HalkbankGateway::hash($form->fields, self::CONFIG['store_key']), $form->fields['HASH']);

        $testConfig = self::CONFIG;
        unset($testConfig['gateway_url']);
        $this->assertSame(HalkbankGateway::URL_TEST, Gateways::make(Gateways::HALKBANK, $testConfig, testMode: true)->checkout($request)->url);
    }

    public function test_config_needs_the_gateway_url_only_live(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Gateways::make(Gateways::HALKBANK, ['client_id' => '1', 'store_key' => 'k'], testMode: false);
    }

    public function test_an_approved_signed_callback_is_a_payment(): void
    {
        $result = Gateways::make(Gateways::HALKBANK, self::CONFIG, false)->handleCallback($this->signedCallback());

        $this->assertSame(Gateways::HALKBANK, $result->provider);
        $this->assertSame('ORD-42-1', $result->paymentId);
        $this->assertSame(2020, $result->amount);
        $this->assertSame('MKD', $result->currency);
        $this->assertSame('25001AbC', $result->reference);
    }

    public function test_a_tampered_callback_is_refused(): void
    {
        $callback = $this->signedCallback();
        $callback['amount'] = '1.00';

        $this->expectException(InvalidCallbackException::class);
        Gateways::make(Gateways::HALKBANK, self::CONFIG, false)->handleCallback($callback);
    }

    public function test_a_signed_declined_callback_is_refused(): void
    {
        $this->expectException(InvalidCallbackException::class);
        $this->expectExceptionMessage('Insufficient funds');

        Gateways::make(Gateways::HALKBANK, self::CONFIG, false)->handleCallback($this->signedCallback(['Response' => 'Declined', 'ProcReturnCode' => '51', 'ErrMsg' => 'Insufficient funds']));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signedCallback(array $overrides = []): array
    {
        $callback = [
            'clientid' => '180000123',
            'oid' => 'ORD-42-1',
            'amount' => '2020.00',
            'currency' => '807',
            'Response' => 'Approved',
            'ProcReturnCode' => '00',
            'mdStatus' => '1',
            'TransId' => '25001AbC',
            'EXTRA_TRXDATE' => '20261111 10:00:00',
            'hashAlgorithm' => 'ver3',
            ...$overrides,
        ];

        return [...$callback, 'HASH' => HalkbankGateway::hash($callback, self::CONFIG['store_key'])];
    }
}
