<?php

use FacturaScripts\Plugins\ZasadyBilling\Lib\IssuerGuard;
use FacturaScripts\Plugins\ZasadyBilling\Lib\Profile;
use PHPUnit\Framework\TestCase;

/**
 * Only exercises the blockers that never need a Cliente DB lookup: profile
 * built via Profile::fromArray() (no settings table involved) with an empty
 * codcliente, so IssuerGuard::check() never reaches `new Cliente()`. The
 * Period::isIssued() call underneath is also DB-free here: Tools::settings()
 * short-circuits to [] when no db_name is configured (see Core/Tools.php),
 * which is the case for this bare phpunit run (no FacturaScripts bootstrap).
 */
final class IssuerGuardTest extends TestCase
{
    public function testDisabledProfileIsBlocked(): void
    {
        $p = Profile::fromArray(2, [
            'enabled' => '0', 'codserie' => '', 'codcliente' => '', 'amount' => '0',
        ]);
        $blockers = IssuerGuard::check($p, null, '2026-09-19');

        $this->assertContains('profile disabled', $blockers);
        $this->assertContains('amount not set', $blockers);
        $this->assertContains('client or series missing', $blockers);
        $this->assertContains('no ECB rate within 5 days of 2026-09-19', $blockers);
    }

    public function testZeroAmountIsBlockedEvenWhenOtherwiseConfigured(): void
    {
        $p = Profile::fromArray(2, [
            'enabled' => '1', 'codserie' => 'F26', 'codcliente' => '', 'amount' => '0',
        ]);
        $blockers = IssuerGuard::check($p, null, '2026-09-19');

        $this->assertContains('amount not set', $blockers);
        $this->assertNotContains('profile disabled', $blockers);
    }

    public function testEmptyCodclienteNeverReachesTheClienteLookup(): void
    {
        // If this reached `new Cliente()`/load(), it would need a real DB
        // connection and this bare-bootstrap test would fatal instead of
        // asserting - so simply completing proves the ordering holds.
        $p = Profile::fromArray(2, ['enabled' => '0', 'codserie' => '', 'codcliente' => '', 'amount' => '0']);
        $blockers = IssuerGuard::check($p, null, '2026-09-19');

        $this->assertNotContains('client missing or without billing address', $blockers);
    }
}
