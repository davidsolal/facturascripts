<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

final class Rate
{
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
