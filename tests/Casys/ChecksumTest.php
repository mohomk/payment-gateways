<?php

namespace Moho\PaymentGateways\Tests\Casys;

use Moho\PaymentGateways\Casys\Checksum;
use PHPUnit\Framework\TestCase;

class ChecksumTest extends TestCase
{
    /** Signed fields; expected values were produced by Cream Portal's production implementation. */
    private const PARAMS = [
        'AmountToPay' => '10000',
        'PayToMerchant' => '1000000123',
        'MerchantName' => 'Мохо ДООЕЛ',
        'AmountCurrency' => 'MKD',
        'Details1' => 'Нарачка',
        'Details2' => 'ORD-42',
        'PaymentOKURL' => 'https://shop.test/ok',
        'PaymentFailURL' => 'https://shop.test/fail',
        'Email' => 'a@b.mk',
        'OriginalAmount' => '100',
        'OriginalCurrency' => 'MKD',
    ];

    private const HEADER = '11AmountToPay,PayToMerchant,MerchantName,AmountCurrency,Details1,Details2,PaymentOKURL,PaymentFailURL,Email,OriginalAmount,OriginalCurrency,005010010003007006020022006003003';

    public function test_new_api_checksum_matches_portal_implementation(): void
    {
        $this->assertSame(
            ['header' => self::HEADER, 'checksum' => 'c1d4a11d370f9debe25b4a88246e90cb482fe32eddbfffa67898281d2087ce8d'],
            Checksum::make(self::PARAMS, 'TEST_PASS'),
        );
    }

    public function test_old_api_checksum_matches_portal_implementation(): void
    {
        $this->assertSame(
            ['header' => self::HEADER, 'checksum' => '96f7bad136e7158823123330562c19a2'],
            Checksum::make(self::PARAMS, 'secret', oldApi: true),
        );
    }

    public function test_verify_return_accepts_a_valid_callback_in_any_case(): void
    {
        $this->assertTrue(Checksum::verifyReturn($this->signedCallback('key'), 'key'));
        $this->assertTrue(Checksum::verifyReturn($this->signedCallback('key', oldApi: true, upper: true), 'key', oldApi: true));
    }

    public function test_verify_return_rejects_tampered_wrong_key_or_unsigned_callbacks(): void
    {
        $this->assertFalse(Checksum::verifyReturn(['AmountToPay' => '1'] + $this->signedCallback('key'), 'key'));
        $this->assertFalse(Checksum::verifyReturn($this->signedCallback('key'), 'other'));
        $this->assertFalse(Checksum::verifyReturn(self::PARAMS, 'key'));
        $this->assertFalse(Checksum::verifyReturn(['ReturnCheckSumHeader' => [], 'ReturnCheckSum' => 'x'] + self::PARAMS, 'key'));
    }

    /**
     * @return array<string, mixed>
     */
    private function signedCallback(string $key, bool $oldApi = false, bool $upper = false): array
    {
        $signed = self::PARAMS + ['cPayPaymentRef' => '987654'];
        ['header' => $header, 'checksum' => $checksum] = Checksum::make($signed, $key, $oldApi);

        return $signed + ['ReturnCheckSumHeader' => $header, 'ReturnCheckSum' => $upper ? strtoupper($checksum) : $checksum];
    }
}
