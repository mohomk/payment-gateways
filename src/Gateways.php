<?php

namespace Moho\PaymentGateways;

use InvalidArgumentException;
use Moho\PaymentGateways\Casys\CasysGateway;

/**
 * Builds a gateway from a provider type and its stored merchant config.
 */
final class Gateways
{
    public const CASYS = 'casys';

    /**
     * @param  array<string, mixed>  $config  the provider's merchant config, as stored by the app
     */
    public static function make(string $type, array $config, bool $testMode): PaymentGateway
    {
        return match ($type) {
            self::CASYS => new CasysGateway($config, $testMode),
            default => throw new InvalidArgumentException("Unsupported payment provider [{$type}]."),
        };
    }
}
