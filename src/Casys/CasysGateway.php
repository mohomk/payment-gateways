<?php

namespace Moho\PaymentGateways\Casys;

use InvalidArgumentException;
use Moho\PaymentGateways\Gateways;
use Moho\PaymentGateways\InvalidCallbackException;
use Moho\PaymentGateways\PaymentGateway;
use Moho\PaymentGateways\PaymentRequest;
use Moho\PaymentGateways\PaymentResult;
use Moho\PaymentGateways\RedirectForm;

/**
 * cPay (Casys) hosted payment page.
 *
 * Config keys: merchant_id, merchant_name, merchant_password, merchant_details (optional),
 * api_version ('new' default, or 'old').
 */
final class CasysGateway implements PaymentGateway
{
    public const URL_TEST = 'https://vpos.cpay.com.mk/mk-MK/TestChecksum';

    public const URL_NEW = 'https://vpos.cpay.com.mk/mk-MK';

    public const URL_OLD = 'https://www.cpay.com.mk/client/Page/default.aspx?xml_id=/.loginToPay/.simple/';

    /** cPay's fixed checksum key on its test endpoint. */
    public const TEST_KEY = 'TEST_PASS';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config, private readonly bool $testMode)
    {
        foreach (['merchant_id', 'merchant_name', 'merchant_password'] as $key) {
            if (($config[$key] ?? '') === '' && !($key === 'merchant_password' && $testMode)) {
                throw new InvalidArgumentException("cPay config is missing [{$key}].");
            }
        }
    }

    public function checkout(PaymentRequest $request): RedirectForm
    {
        $required = [
            // cPay wants the amount ×100; amounts are whole denars, deni are no longer used.
            'AmountToPay' => (string) ($request->total() * 100),
            'PayToMerchant' => (string) $this->config['merchant_id'],
            'MerchantName' => (string) $this->config['merchant_name'],
            'AmountCurrency' => $request->currency,
            'Details1' => (string) (($this->config['merchant_details'] ?? '') ?: $request->description ?: $request->paymentId),
            'Details2' => $request->paymentId,
            'PaymentOKURL' => $request->successUrl,
            'PaymentFailURL' => $request->failUrl,
        ];

        // Empty optional fields must be left out of both the form and the checksum.
        $optional = array_filter([
            'FirstName' => (string) $request->firstName,
            'LastName' => (string) $request->lastName,
            'Email' => (string) $request->email,
            'Address' => (string) $request->address,
            'City' => (string) $request->city,
            'Zip' => (string) $request->zip,
            'Country' => (string) $request->country,
            'Telephone' => (string) $request->phone,
            'OriginalAmount' => (string) $request->amount,
            'OriginalCurrency' => $request->currency,
            'Fee' => $request->fee > 0 ? (string) $request->fee : '',
        ], fn(string $value): bool => $value !== '');

        ['header' => $header, 'checksum' => $checksum] = Checksum::make([...$required, ...$optional], $this->key(), $this->isOldApi());

        return new RedirectForm($this->url(), [...$required, 'checkSumHeader' => $header, 'checkSum' => $checksum, ...$optional]);
    }

    public function handleCallback(array $callback): PaymentResult
    {
        if (!Checksum::verifyReturn($callback, $this->key(), $this->isOldApi())) {
            throw new InvalidCallbackException('cPay callback checksum is missing or invalid.');
        }

        return new PaymentResult(
            provider: Gateways::CASYS,
            paymentId: (string) ($callback['Details2'] ?? ''),
            amount: intdiv((int) ($callback['AmountToPay'] ?? 0), 100),
            currency: (string) ($callback['AmountCurrency'] ?? ''),
            reference: isset($callback['cPayPaymentRef']) ? (string) $callback['cPayPaymentRef'] : null,
            raw: $callback,
        );
    }

    private function url(): string
    {
        if ($this->testMode) {
            return self::URL_TEST;
        }

        return $this->isOldApi() ? self::URL_OLD : self::URL_NEW;
    }

    private function key(): string
    {
        return $this->testMode ? self::TEST_KEY : (string) $this->config['merchant_password'];
    }

    private function isOldApi(): bool
    {
        return ($this->config['api_version'] ?? 'new') === 'old';
    }
}
