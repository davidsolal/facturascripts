<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

final class Rate
{
    /** Rate taken automatically from the ECB euro reference rates. */
    public const SOURCE_ECB = 'ecb';
    /** Rate supplied by the operator for one invoice, overriding the ECB value. */
    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        public readonly float $usdPerEur,
        public readonly string $dateUsed,
        public readonly string $source
    ) {
    }

    /** EUR amount for a foreign-currency amount, using core semantics (total / tasaconv). */
    public function toEur(float $usd): float
    {
        return $this->usdPerEur === 0.0 ? 0.0 : round($usd / $this->usdPerEur, 2);
    }
}
