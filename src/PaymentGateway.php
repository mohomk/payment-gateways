<?php

namespace Moho\PaymentGateways;

/**
 * A hosted payment page: the customer is sent to the provider with a signed form
 * and the provider posts a signed callback back to one of our URLs.
 */
interface PaymentGateway
{
    /**
     * The form the customer's browser must post to the provider.
     */
    public function checkout(PaymentRequest $request): RedirectForm;

    /**
     * Verifies the provider's signed callback and reads the payment from it.
     *
     * @param  array<string, mixed>  $callback  the request data the provider posted back
     *
     * @throws InvalidCallbackException when the signature is missing or wrong, or the payment didn't complete
     */
    public function handleCallback(array $callback): PaymentResult;
}
