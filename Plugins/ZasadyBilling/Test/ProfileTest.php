<?php

use FacturaScripts\Plugins\ZasadyBilling\Lib\Profile;
use PHPUnit\Framework\TestCase;

final class ProfileTest extends TestCase
{
    public function testDefaultsWhenNothingStored(): void
    {
        $p = Profile::fromArray(2, []);
        $this->assertSame(19, $p->day);
        $this->assertSame(0.0, $p->rateOverride);
        $this->assertFalse($p->enabled);
    }

    public function testFromArrayReadsStoredValues(): void
    {
        $p = Profile::fromArray(2, [
            'enabled' => '1', 'codserie' => 'F26', 'codcliente' => 'ZASADY',
            'amount' => '5000', 'day' => '19', 'rate_override' => '1.146',
        ]);
        $this->assertTrue($p->enabled);
        $this->assertSame('F26', $p->codserie);
        $this->assertSame(5000.0, $p->amount);
        $this->assertSame(1.146, $p->rateOverride);
    }
}
