# moho/payment-gateways

[![Tests](https://github.com/mohomk/payment-gateways/actions/workflows/tests.yml/badge.svg)](https://github.com/mohomk/payment-gateways/actions/workflows/tests.yml)

Hosted payment page gateways for Macedonian payment providers, built for [Cream ERP](https://cream.mk).

The package builds the signed request that sends a customer to the provider's payment page, and verifies the callback that brings them back. It is plain PHP (^8.2) with no framework dependency: the application keeps routes, sessions, payment records and merchant credentials.

| Provider | Type | Payment page | Status |
|---|---|---|---|
| cPay (Casys) | `casys` | signed `POST` form | Supported |
| NLB (Bankart) | `nlb` | redirect from Bankart's API | Beta: tested against Bankart's API documentation, not yet with a live account |

## Contents

- [Installation](#installation)
- [Usage](#usage)
- [Security](#security)
- [Providers](#providers)
- [Adding a provider](#adding-a-provider)
- [Tests](#tests)
- [Contributing](#contributing)
- [License](#license)

## Installation

The package is not on Packagist yet, so add its repository first:

```bash
composer config repositories.payment-gateways vcs https://github.com/mohomk/payment-gateways
composer require moho/payment-gateways
```

To work on the package next to your application, use a path repository instead (symlinked into `vendor/`):

```bash
composer config repositories.payment-gateways path ../payment-gateways
composer require "moho/payment-gateways:@dev"
```

## Usage

A payment takes three steps: build the gateway, send the customer to the payment page, and verify the callback when they return.

### 1. Build the gateway

```php
use Moho\PaymentGateways\Gateways;

$gateway = Gateways::make(Gateways::CASYS, $config, testMode: false);
```

`$config` holds the provider's merchant details (see [Providers](#providers)); a missing required key throws `InvalidArgumentException`.

Providers called from the server (NLB) use a small built-in HTTP client, which needs `allow_url_fopen`. To use your own client (for logging, timeouts or fakes in tests), pass a closure that sends the request and returns the response body. With Laravel's HTTP client:

```php
use Illuminate\Support\Facades\Http;

$gateway = Gateways::make(Gateways::NLB, $config, false, fn (string $method, string $url, array $headers, string $body): string => Http::withHeaders($headers)
    ->timeout(30)
    ->when($body !== '', fn ($request) => $request->withBody($body, $headers['Content-Type']))
    ->send($method, $url)
    ->body());
```

### 2. Send the customer to the payment page

```php
use Moho\PaymentGateways\PaymentRequest;

$form = $gateway->checkout(new PaymentRequest(
    paymentId: 'ORD-42-1',               // unique per attempt, echoed back in the callback
    amount: 1990,                        // 1.990 MKD
    currency: 'MKD',
    successUrl: route('payment.success'),
    failUrl: route('payment.fail'),
    fee: 30,                             // optional, charged on top of the amount
    description: 'Order #42',            // optional
    firstName: 'Ana',                    // optional payer details,
    lastName: 'Petrovska',               // prefilled on the payment page
    email: 'ana@example.mk',
    address: 'Partizanska 1',
    city: 'Skopje',
    zip: '1000',
    country: 'North Macedonia',
    phone: '+38970123123',
));
```

Amounts are **whole denars** as integers. Round the order total before paying, so the customer is charged exactly what they saw; the provider charges `amount + fee`. Use a new `paymentId` for every attempt: providers refuse one they have already seen.

`checkout()` returns a `RedirectForm` (`url`, `method`, `fields`). Render it and submit it straight away, keeping the field order:

```blade
<form method="{{ $form->method }}" action="{{ $form->url }}" id="pay">
    @foreach ($form->fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
</form>
<script>document.getElementById('pay').submit();</script>
```

### 3. Verify the callback

Call `handleCallback()` on the **success** return only, with everything the provider sent (query string and body):

```php
use Moho\PaymentGateways\InvalidCallbackException;

try {
    $result = $gateway->handleCallback($request->all());
} catch (InvalidCallbackException) {
    return redirect()->route('payment.failed'); // not a completed payment
}

$result->provider;   // 'casys' or 'nlb'
$result->paymentId;  // the paymentId you sent
$result->amount;     // total charged in denars, fee included
$result->currency;
$result->reference;  // the provider's transaction reference, when it sends one
$result->raw;        // the callback (cPay) or Bankart's status answer (NLB)
```

It throws `InvalidCallbackException` unless the callback proves a completed payment. The fail return needs no verification: nothing was paid.

## Security

- **Trust the result, not the URL.** cPay also signs declined and canceled payments, so a signed callback on the success URL proves nothing by itself. `handleCallback()` accepts a cPay callback only when cPay signed its payment reference (`cPayPaymentRef`) together with the payment id and amount; for NLB it asks Bankart's status API.
- **Match it to your payment.** Before marking a payment paid, compare `paymentId` and `amount` with your stored payment.
- **Make the handler idempotent.** Providers may call back more than once, sometimes at the same moment: lock the payment row and process it once.
- **Never take test payments on a live site.** In test mode cPay signs with its public test key (`TEST_PASS`), so anyone can sign a test callback, and test payments take no money.

Found a vulnerability? Please report it privately, as described in [SECURITY.md](SECURITY.md).

## Providers

### cPay (Casys)

| Config key | Required | Notes |
|---|---|---|
| `merchant_id` | yes | sent as `PayToMerchant` |
| `merchant_name` | yes | sent as `MerchantName` |
| `merchant_password` | live only | checksum key; test mode always uses `TEST_PASS` |
| `merchant_details` | no | sent as `Details1`; falls back to the description, then the payment id |
| `api_version` | no | `new` (HMAC-SHA256, default) or `old` (MD5) |

- `AmountToPay` is sent in denars ×100, as cPay requires; `OriginalAmount` and `Fee` in denars.
- Empty optional fields are left out of both the form and the checksum.
- Test mode posts to `https://vpos.cpay.com.mk/mk-MK/TestChecksum`, which only checks the checksum.

### NLB (Bankart)

| Config key | Required | Notes |
|---|---|---|
| `api_key` | yes | Bankart API key |
| `username` | yes | API user |
| `password` | yes | API password |
| `shared_secret` | yes | signs each API call (HMAC-SHA512) |

- `checkout()` calls Bankart's debit API and returns its payment page as a `GET` form. It throws `RuntimeException` when Bankart refuses the payment or can't be reached; send the customer to your failed payment page then.
- `merchantTransactionId={paymentId}` is appended to the success URL, which is also Bankart's `callbackUrl`: Bankart POSTs JSON there from its servers and expects a `200` response with the body `OK`. That URL must be reachable from the internet.
- The fail URL is used for both canceled and failed payments.
- The payer's name, email, address, city, zip and phone are sent as Bankart's customer data; the country is not.
- `handleCallback()` trusts nothing it receives except that id: it asks Bankart's status API and accepts only a successful debit.
- Test mode uses `https://bankart.paymentsandbox.cloud`.

## Adding a provider

1. Create `src/<Provider>/<Provider>Gateway.php` implementing `PaymentGateway`, validating its config in the constructor.
2. Add a type constant and a `match` arm in `Gateways::make()`.
3. Add tests under `tests/<Provider>/`, with checksum or signature vectors from the provider's documentation or a known working implementation.
4. Document its config keys and behaviour here.

## Tests

```bash
composer install
vendor/bin/phpunit
```

## Contributing

Issues and pull requests are welcome. Please add tests for any change, and report security issues privately as described in [SECURITY.md](SECURITY.md).

## License

MIT, see [LICENSE](LICENSE). Made by [Moho](https://moho.mk) for [Cream ERP](https://cream.mk).
