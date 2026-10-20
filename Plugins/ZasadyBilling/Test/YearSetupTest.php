<?php

use FacturaScripts\Plugins\ZasadyBilling\Lib\YearSetup;
use PHPUnit\Framework\TestCase;

/**
 * Covers only the pure, database-free parts of YearSetup:
 *  - candidateCode(), which predicts the exercise code
 *    Ejercicio::createNew() would assign (plain year, then '00'+yy, then
 *    '0'+idempresa+yy, then the first free 0001-9999) given an injected
 *    $exists() closure instead of a real table;
 *  - missingAccountingPlanBlocker(), the exact blocker sentence
 *    IssuerGuard adds.
 *
 * preview(), ensure() and hasAccountingPlan() all load/save real models
 * (Ejercicio, SecuenciaDocumento, Subcuenta) and need a live database
 * connection - they are exercised by the acceptance run instead
 * (year-check.php against the 2026 and 2027 dates), not faked here.
 */
final class YearSetupTest extends TestCase
{
    public function testCandidateCodeUsesThePlainYearWhenFree(): void
    {
        $exists = static fn (string $code): bool => false;

        $this->assertSame('2027', YearSetup::candidateCode('2027-01-19', 1, $exists));
    }

    public function testCandidateCodeFallsBackTo00yyWhenTheYearIsTaken(): void
    {
        // Mirrors the real 2026 data: company 1 already holds codejercicio
        // '2026' (ejercicios is keyed by code alone, not per company), so
        // company 2's exercise for the same year falls back to '0026' -
        // exactly what's seeded in the instance today.
        $exists = static fn (string $code): bool => $code === '2027';

        $this->assertSame('0027', YearSetup::candidateCode('2027-06-01', 2, $exists));
    }

    public function testCandidateCodeFallsBackToCompanyPrefixedCodeWhenBothTaken(): void
    {
        $exists = static fn (string $code): bool => in_array($code, ['2027', '0027'], true);

        $this->assertSame('0227', YearSetup::candidateCode('2027-06-01', 2, $exists));
    }

    public function testCandidateCodeFallsBackToFirstFreeNumericCodeWhenAllThreeAreTaken(): void
    {
        $taken = ['2027', '0027', '0227', '0001'];
        $exists = static fn (string $code): bool => in_array($code, $taken, true);

        $this->assertSame('0002', YearSetup::candidateCode('2027-06-01', 2, $exists));
    }

    public function testCandidateCodeRejectsAnUnparseableDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        YearSetup::candidateCode('not-a-date', 1, static fn (string $code): bool => false);
    }

    public function testMissingAccountingPlanBlockerNamesTheExercise(): void
    {
        $this->assertSame(
            'exercise 2027 has no chart of accounts (import the PGC first)',
            YearSetup::missingAccountingPlanBlocker('2027')
        );
    }

    public function testPatternAndLongnumeroConstantsMatchTheExisting2026Sequences(): void
    {
        // The whole point of this plugin's pattern is that it NEVER changes
        // across years - only {ANYO2} varies at generateCode() time - so
        // these constants must stay exactly what the 2026 sequences already
        // use (seen in secuencias_documentos for both companies).
        $this->assertSame('F{ANYO2}{0NUM}', YearSetup::PATTERN);
        $this->assertSame(5, YearSetup::LONGNUMERO);
    }
}
