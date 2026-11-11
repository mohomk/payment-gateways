<?php

namespace Moho\PaymentGateways\Halkbank;

use InvalidArgumentException;
use Moho\PaymentGateways\Gateways;
use Moho\PaymentGateways\InvalidCallbackException;
use Moho\PaymentGateways\PaymentGateway;
use Moho\PaymentGateways\PaymentRequest;
use Moho\PaymentGateways\PaymentResult;
use Moho\PaymentGateways\RedirectForm;

/**
 * Halkbank (Skopje) card payments on Asseco's NestPay platform: 3D Pay Hosting with hash version 3.
 *
 * The form and the callback are both signed with the store key over every field (SHA-512), so a callback that
 * verifies came from NestPay; it proves a payment only when NestPay approved it.
 *
 * Config keys: client_id, store_key, gateway_url (live only: the est3Dgate URL from the bank).
 */
final class HalkbankGateway implements PaymentGateway
{
    public const URL_TEST = 'https://torus-stage-halkbankmacedonia.asseco-see.com.tr/fim/est3Dgate';

    /** ISO 4217 numeric codes NestPay expects. */
    private const CURRENCIES = ['MKD' => '807', 'EUR' => '978', 'USD' => '840'];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config, private readonly bool $testMode)
    {
        foreach (['client_id', 'store_key', 'gateway_url'] as $key) {
            if (($config[$key] ?? '') === '' && !($key === 'gateway_url' && $testMode)) {
                throw new InvalidArgumentException("Halkbank config is missing [{$key}].");
            }
        }
    }

    public function checkout(PaymentRequest $request): RedirectForm
    {
        $fields = array_filter([
            'clientid' => (string) $this->config['client_id'],
            'storetype' => '3d_pay_hosting',
            'TranType' => 'Auth',
            'amount' => number_format($request->total(), 2, '.', ''),
            'currency' => self::CURRENCIES[$request->currency] ?? throw new InvalidArgumentException("Halkbank does not take [{$request->currency}]."),
            'oid' => $request->paymentId,
            'okUrl' => $request->successUrl,
            'failUrl' => $request->failUrl,
            'lang' => 'mk',
            'rnd' => bin2hex(random_bytes(10)),
            'hashAlgorithm' => 'ver3',
            'description' => (string) $request->description,
            'BillToName' => trim($request->firstName . ' ' . $request->lastName),
            'email' => (string) $request->email,
            'tel' => (string) $request->phone,
            'BillToStreet1' => (string) $request->address,
            'BillToCity' => (string) $request->city,
            'BillToPostalCode' => (string) $request->zip,
        ], fn(string $value): bool => $value !== '');

        return new RedirectForm($this->testMode ? self::URL_TEST : (string) $this->config['gateway_url'], [...$fields, 'HASH' => self::hash($fields, (string) $this->config['store_key'])]);
    }

    public function handleCallback(array $callback): PaymentResult
    {
        if (!hash_equals(self::hash($callback, (string) $this->config['store_key']), (string) ($callback['HASH'] ?? ''))) {
            throw new InvalidCallbackException('Halkbank callback hash is missing or invalid.');
        }

        // NestPay signs declined payments too: only an approved one took the money.
        if (($callback['Response'] ?? null) !== 'Approved' || ($callback['ProcReturnCode'] ?? null) !== '00') {
            throw new InvalidCallbackException('Halkbank did not approve the payment: ' . ($callback['ErrMsg'] ?? $callback['Response'] ?? 'no response') . '.');
        }

        $cents = (int) round((float) ($callback['amount'] ?? 0) * 100);

        if ($cents % 100 !== 0) {
            throw new InvalidCallbackException('Halkbank callback amount is not in whole denars.');
        }

        return new PaymentResult(
            provider: Gateways::HALKBANK,
            paymentId: (string) ($callback['oid'] ?? ''),
            amount: intdiv($cents, 100),
            currency: (string) (array_search((string) ($callback['currency'] ?? ''), self::CURRENCIES, true) ?: ($callback['currency'] ?? '')),
            reference: isset($callback['TransId']) ? (string) $callback['TransId'] : null,
            raw: $callback,
        );
    }

    /**
     * Hash version 3: every field's value except HASH and encoding, in the case-insensitive natural order of the
     * names, then the store key, joined by `|` (with `\` and `|` escaped), as base64 of the SHA-512 digest.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function hash(array $fields, string $storeKey): string
    {
        $names = array_keys($fields);
        natcasesort($names);
        $escape = fn(string $value): string => str_replace('|', '\\|', str_replace('\\', '\\\\', $value));
        $plain = '';

        foreach ($names as $name) {
            if (!in_array(strtolower((string) $name), ['hash', 'encoding'], true)) {
                $plain .= $escape((string) $fields[$name]) . '|';
            }
        }

        return base64_encode(hash('sha512', $plain . $escape($storeKey), true));
    }
}
