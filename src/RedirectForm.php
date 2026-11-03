<?php

namespace Moho\PaymentGateways;

final readonly class RedirectForm
{
    /**
     * @param  array<string, string>  $fields  hidden inputs, in the order the provider signed them
     */
    public function __construct(
        public string $url,
        public array $fields,
        public string $method = 'POST',
    ) {}
}
