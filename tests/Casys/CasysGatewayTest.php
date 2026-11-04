<?php

namespace Moho\PaymentGateways\Tests\Casys;

use InvalidArgumentException;
use Moho\PaymentGateways\Casys\CasysGateway;
use Moho\PaymentGateways\Casys\Checksum;
use Moho\PaymentGateways\Gateways;
use Moho\PaymentGateways\InvalidCallbackException;
use Moho\PaymentGateways\PaymentRequest;
use PHPUnit\Framework\TestCase;

class CasysGatewayTest extends TestCase
{
    private const CONFIG = [
        'merchant_id' => '1000000123',
        'merchant_name' => 'Moho',
        'merchant_password' => 'live-secret',
        'merchant_details' => 'Web shop',
    ];

    public function test_checkout_builds_a_signed_form_with_amount_times_100(): void
    {
        $form = Gateways::make(Gateways::CASYS, self::CONFIG, testMode: false)->checkout(new PaymentRequest(
            paymentId: 'ORD-42',
            amount: 100,
            currency: 'MKD',
            successUrl: 'https://shop.test/ok',
            failUrl: 'https://shop.test/fail',
            fee: 1,
            email: 'a@b.mk',
        ));

        $this->assertSame(CasysGateway::URL_NEW, $form->url);
        $this->assertSame('10100', $form->fields['AmountToPay']);
        $this->assertSame('100', $form->fields['OriginalAmount']);
        $this->assertSame('1', $form->fields['Fee']);
        $this->assertSame('Web shop', $form->fields['Details1']);
        $this->assertSame('ORD-42', $form->fields['Details2']);
        $this->assertArrayNotHasKey('FirstName', $form->fields);

        $signed = array_diff_key($form->fields, array_flip(['checkSumHeader', 'checkSum']));
        $this->assertSame(Checksum::make($signed, 'live-secret')['checksum'], $form->fields['checkSum']);
    }

    public function test_checkout_sends_and_signs_the_payers_details(): void
    {
        $form = Gateways::make(Gateways::CASYS, self::CONFIG, testMode: false)->checkout(new PaymentRequest(
            paymentId: 'ORD-43',
            amount: 100,
            currency: 'MKD',
            successUrl: 'https://shop.test/ok',
            failUrl: 'https://shop.test/fail',
            firstName: 'Ана',
            lastName: 'Петрова',
            email: 'a@b.mk',
            address: 'Партизанска 1',
            city: 'Скопје',
            zip: '1000',
            country: 'Македонија',
            phone: '+38970123123',
        ));

        $this->assertSame('Ана', $form->fields['FirstName']);
        $this->assertSame('Петрова', $form->fields['LastName']);
        $this->assertSame('Партизанска 1', $form->fields['Address']);
        $this->assertSame('Скопје', $form->fields['City']);
        $this->assertSame('1000', $form->fields['Zip']);
        $this->assertSame('Македонија', $form->fields['Country']);
        $this->assertSame('+38970123123', $form->fields['Telephone']);

        $signed = array_diff_key($form->fields, array_flip(['checkSumHeader', 'checkSum']));
        $this->assertSame(Checksum::make($signed, 'live-secret')['checksum'], $form->fields['checkSum']);
    }

    public function test_test_mode_uses_test_endpoint_and_key_without_a_password(): void
    {
        $config = ['merchant_password' => ''] + self::CONFIG;
        $form = Gateways::make(Gateways::CASYS, $config, testMode: true)
            ->checkout(new PaymentRequest('P1', 100, 'MKD', 'https://ok', 'https://fail'));

        $signed = array_diff_key($form->fields, array_flip(['checkSumHeader', 'checkSum']));
        $this->assertSame(CasysGateway::URL_TEST, $form->url);
        $this->assertSame(Checksum::make($signed, CasysGateway::TEST_KEY)['checksum'], $form->fields['checkSum']);
    }

    public function test_old_api_uses_old_url_and_md5(): void
    {
        $form = Gateways::make(Gateways::CASYS, ['api_version' => 'old'] + self::CONFIG, testMode: false)
            ->checkout(new PaymentRequest('P1', 100, 'MKD', 'https://ok', 'https://fail'));

        $this->assertSame(CasysGateway::URL_OLD, $form->url);
        $this->assertSame(32, strlen($form->fields['checkSum']));
    }

    public function test_handle_callback_returns_the_payment(): void
    {
        $signed = ['AmountToPay' => '10100', 'AmountCurrency' => 'MKD', 'Details2' => 'ORD-42', 'cPayPaymentRef' => '987654'];
        ['header' => $header, 'checksum' => $checksum] = Checksum::make($signed, 'live-secret');

        $result = Gateways::make(Gateways::CASYS, self::CONFIG, testMode: false)
            ->handleCallback($signed + ['ReturnCheckSumHeader' => $header, 'ReturnCheckSum' => $checksum]);

        $this->assertSame(['casys', 'ORD-42', 101, 'MKD', '987654'], [$result->provider, $result->paymentId, $result->amount, $result->currency, $result->reference]);
    }

    public function test_handle_callback_rejects_a_bad_signature(): void
    {
        $this->expectException(InvalidCallbackException::class);

        Gateways::make(Gateways::CASYS, self::CONFIG, testMode: false)
            ->handleCallback(['Details2' => 'ORD-42', 'ReturnCheckSumHeader' => '01Details2,006', 'ReturnCheckSum' => 'forged']);
    }

    public function test_missing_live_config_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Gateways::make(Gateways::CASYS, ['merchant_password' => ''] + self::CONFIG, testMode: false);
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Gateways::make('stripe', [], testMode: false);
    }
}
