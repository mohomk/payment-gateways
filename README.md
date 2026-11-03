# moho/payment-gateways

Hosted payment page gateways for Macedonian payment providers, shared by Cream ERP and Cream Portal.

Plain PHP (^8.2), no framework dependency. The app owns routes, sessions, storage of payments and merchant credentials; this package only builds the signed request and verifies the signed callback.

| Provider | Type | Status |
|---|---|---|
| cPay (Casys) | `casys` | Supported |

## Installation

```bash
composer config repositories.payment-gateways vcs https://github.com/mohomk/payment-gateways
composer require moho/payment-gateways
```

For local development next to the app, use a path repository instead (symlinked into `vendor/`):

```bash
composer config repositories.payment-gateways path ../payment-gateways
composer require "moho/payment-gateways:@dev"
```

## Usage

### 1. Build the gateway

```php
use Moho\PaymentGateways\Gateways;

// In Cream: from a PortalPaymentProvider record
$gateway = Gateways::make($provider->type, $provider->config, $provider->is_test_mode);
```

### 2. Send the customer to the payment page

All amounts are **whole denars** as integers (deni are no longer used). Round the order total in the app before paying, so the charged amount equals what the customer saw.

```php
use Moho\PaymentGateways\PaymentRequest;

$form = $gateway->checkout(new PaymentRequest(
    paymentId: $payment->public_id,     // echoed back in the callback
    amount: 1990,                       // 1.990 MKD
    currency: 'MKD',
    successUrl: route('payment.success'),
    failUrl: route('payment.fail'),
    fee: 30,                            // optional, charged on top
    description: 'Order #42',           // optional
    firstName: $user->first_name,       // optional
    lastName: $user->last_name,         // optional
    email: $user->email,                // optional
));
```

Render an auto-submitting form, keeping the field order:

```blade
<form method="{{ $form->method }}" action="{{ $form->url }}" id="pay">
    @foreach ($form->fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
</form>
<script>document.getElementById('pay').submit();</script>
```

### 3. Handle the callback

```php
use Moho\PaymentGateways\InvalidCallbackException;

try {
    $result = $gateway->handleCallback($request->all());
} catch (InvalidCallbackException) {
    abort(400);
}

$result->paymentId;  // your paymentId
$result->amount;     // total charged in denars, fee included
$result->currency;
$result->reference;  // provider's transaction reference
$result->raw;        // callback as received
```

A valid signature proves the data came from the provider. Whether the payment succeeded is decided by which URL it returned to (success or fail). Always compare `paymentId` and `amount` with your stored payment before marking it paid, and make the handler idempotent.

## Providers

### cPay (Casys)

Config keys (as stored in `PortalPaymentProvider.config`):

| Key | Required | Notes |
|---|---|---|
| `merchant_id` | yes | `PayToMerchant` |
| `merchant_name` | yes | `MerchantName` |
| `merchant_password` | live only | checksum key; test mode always uses `TEST_PASS` |
| `merchant_details` | no | `Details1`; falls back to the request description, then the payment id |
| `api_version` | no | `new` (HMAC-SHA256, default) or `old` (MD5) |

`AmountToPay` is sent as denars ×100, as cPay requires; `OriginalAmount` and `Fee` are sent in denars.

Test mode posts to `https://vpos.cpay.com.mk/mk-MK/TestChecksum`, which only checks the checksum.

## Adding a provider

1. Create `src/<Provider>/<Provider>Gateway.php` implementing `PaymentGateway`; validate its config in the constructor.
2. Add a type constant and a `match` arm in `Gateways::make()`.
3. Add tests under `tests/<Provider>/`, with checksum/hash vectors taken from the provider's documentation or a known working implementation.

## Tests

```bash
composer install
vendor/bin/phpunit
```
