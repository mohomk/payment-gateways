<?php

namespace Moho\PaymentGateways;

use Closure;
use InvalidArgumentException;
use Moho\PaymentGateways\Casys\CasysGateway;
use Moho\PaymentGateways\Halkbank\HalkbankGateway;
use Moho\PaymentGateways\Nlb\NlbGateway;

/**
 * Builds a gateway from a provider type and its stored merchant config.
 */
final class Gateways
{
    public const CASYS = 'casys';

    public const NLB = 'nlb';

    public const HALKBANK = 'halkbank';

    /**
     * @param  array<string, mixed>  $config  the provider's merchant config, as stored by the app
     * @param  ?Closure  $send  the HTTP client for providers called from the server (see NlbGateway), so the app can use its own
     */
    public static function make(string $type, array $config, bool $testMode, ?Closure $send = null): PaymentGateway
    {
        return match ($type) {
            self::CASYS => new CasysGateway($config, $testMode),
            self::NLB => new NlbGateway($config, $testMode, $send),
            self::HALKBANK => new HalkbankGateway($config, $testMode),
            default => throw new InvalidArgumentException("Unsupported payment provider [{$type}]."),
        };
    }
}
