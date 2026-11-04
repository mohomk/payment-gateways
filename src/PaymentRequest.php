<?php

namespace Moho\PaymentGateways;

final readonly class PaymentRequest
{
    /**
     * @param  string  $paymentId  our id, echoed back by the provider in the callback
     * @param  int  $amount  in whole currency units (denars), without the fee
     * @param  int  $fee  in whole currency units, charged on top of the amount
     *
     * The payer's details (name, email, address, phone) are optional; providers that take them prefill their payment page.
     */
    public function __construct(
        public string $paymentId,
        public int $amount,
        public string $currency,
        public string $successUrl,
        public string $failUrl,
        public int $fee = 0,
        public ?string $description = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $city = null,
        public ?string $zip = null,
        public ?string $country = null,
        public ?string $phone = null,
    ) {}

    public function total(): int
    {
        return $this->amount + $this->fee;
    }
}
