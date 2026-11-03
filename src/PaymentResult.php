<?php

namespace Moho\PaymentGateways;

final readonly class PaymentResult
{
    /**
     * @param  int  $amount  total charged, in whole currency units, fee included
     * @param  ?string  $reference  the provider's own transaction reference
     * @param  array<string, mixed>  $raw  the callback as received
     */
    public function __construct(
        public string $provider,
        public string $paymentId,
        public int $amount,
        public string $currency,
        public ?string $reference,
        public array $raw,
    ) {}
}
