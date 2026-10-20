<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

use FacturaScripts\Core\Model\Ejercicio;
use FacturaScripts\Core\Model\SecuenciaDocumento;
use FacturaScripts\Core\Model\Subcuenta;
use FacturaScripts\Core\Where;

/**
 * Provisions the per-year state a FacturaCliente needs before Issuer::issue()
 * can run: an open Ejercicio covering the invoice date, and a
 * SecuenciaDocumento for (company, series, exercise, FacturaCliente) using
 * this plugin's own pattern instead of core's default.
 *
 * Without this, the FIRST invoice saved in a new year does the provisioning
 * implicitly and wrong:
 *  - BusinessDocument::save() calls Ejercicio::loadFromDate(..., create:
 *    true), which silently creates the exercise - fine in itself, but by
 *    the time it happens the sequence lookup below has nothing to find;
 *  - with no sequence for (company, series, new exercise, FacturaCliente),
 *    BusinessDocumentCode::getSequence() (Core/Lib/BusinessDocumentCode.php
 *    :96-107) auto-creates one using getDefaultPattern(), which for
 *    invoices is the literal string 'F{EJE}{SERIE}{NUM}' - giving codes
 *    like 'F2027F261' instead of this plugin's 'F2700001', and losing the
 *    5-digit padding (longnumero) entirely;
 *  - the new exercise has no chart of accounts, so
 *    InvoiceToAccounting::initialChecks() bails at
 *    'exercise-without-accounting-plan' and the invoice posts no entry.
 *
 * ensure() closes the first two gaps up front, before Issuer::issue() ever
 * runs, so core's own lookups simply find what is already there. The chart
 * of accounts is deliberately NOT auto-provisioned: importing a PGC is an
 * accounting decision with tax-regime consequences this plugin must not make
 * unattended. ensure() never throws on a missing plan; it is reported so
 * IssuerGuard can refuse the run cleanly instead (see
 * missingAccountingPlanBlocker()).
 */
final class YearSetup
{
    /**
     * tipodoc value core keys SecuenciaDocumento rows by - must match
     * FacturaCliente::modelClassName() exactly (BusinessDocumentCode reads
     * $document->modelClassName(), not a hardcoded string).
     */
    public const TIPODOC = 'FacturaCliente';

    /**
     * Pattern this plugin standardises on for every year's F26 sequence:
     * F2700001, F2800001, ... - never core's default F{EJE}{SERIE}{NUM}
     * (e.g. F2027F261) that getSequence() would auto-create otherwise. The
     * series code itself never changes; only the printed year does, via
     * {ANYO2} evaluated at code-generation time (SecuenciaDocumento::
     * generateCode()).
     */
    public const PATTERN = 'F{ANYO2}{0NUM}';

    public const LONGNUMERO = 5;

    /**
     * Read-only snapshot of what ensure() would do for
     * ($idempresa, $codserie, $isoDate). Never saves anything - safe to call
     * from a CLI preflight or a cron dry-run.
     *
     * @return array{
     *     codejercicio: string,
     *     exerciseExists: bool,
     *     exerciseOpen: bool,
     *     sequenceExists: bool,
     *     accountingPlan: bool,
     *     subcuentaCount: int,
     *     pattern: string,
     *     longnumero: int,
     * }
     */
    public static function preview(int $idempresa, string $codserie, string $isoDate): array
    {
        $ejercicio = new Ejercicio();
        $found = $ejercicio->loadWhere([
            Where::eq('idempresa', $idempresa),
            Where::lte('fechainicio', $isoDate),
            Where::gte('fechafin', $isoDate),
        ], ['codejercicio' => 'DESC']);

        if ($found) {
            $codejercicio = (string)$ejercicio->codejercicio;
            $exerciseOpen = $ejercicio->isOpened();
        } else {
            // Nothing covers this date yet: report the code core WOULD
            // assign on creation (see candidateCode()) without creating it.
            $codejercicio = self::candidateCode(
                $isoDate,
                $idempresa,
                static fn (string $code): bool => (new Ejercicio())->load($code)
            );
            $exerciseOpen = false;
        }

        $subcuentaCount = $found ? self::subcuentaCount($codejercicio) : 0;

        return [
            'codejercicio' => $codejercicio,
            'exerciseExists' => $found,
            'exerciseOpen' => $exerciseOpen,
            'sequenceExists' => null !== self::findSequence($idempresa, $codserie, $codejercicio),
            'accountingPlan' => $subcuentaCount > 0,
            'subcuentaCount' => $subcuentaCount,
            'pattern' => self::PATTERN,
            'longnumero' => self::LONGNUMERO,
        ];
    }

    /**
     * Ensures an open exercise covers $isoDate for $idempresa and that a
     * FacturaCliente SecuenciaDocumento exists for (company, series,
     * exercise), creating whatever is missing. Returns the exercise code.
     *
     * Never touches an existing sequence's `numero`: the sequence branch
     * below only runs when findSequence() returned null, i.e. the row is
     * being created for the first time with numero = inicio = 1.
     *
     * A missing chart of accounts is NOT an error here - see the class
     * docblock. Callers that need to refuse on it should check
     * hasAccountingPlan() themselves (IssuerGuard::check() does this).
     */
    public static function ensure(int $idempresa, string $codserie, string $isoDate): string
    {
        $ejercicio = new Ejercicio();
        $ejercicio->idempresa = $idempresa;

        // onlyOpened=false: a CLOSED exercise that already covers the date
        // is still the right one to post into - closing a year is a
        // deliberate operator decision this plugin must never override by
        // creating a duplicate for the same period (see the RULING: do not
        // touch existing exercises). create=true: when nothing covers the
        // date, core creates it exactly as BusinessDocument::save() would -
        // doing it here, before any invoice is built, means the sequence
        // created below never has to race core's own auto-create.
        if (false === $ejercicio->loadFromDate($isoDate, false, true)) {
            throw new \RuntimeException(
                'could not load or create an exercise for ' . $isoDate . ' (company ' . $idempresa . ')'
            );
        }

        $codejercicio = (string)$ejercicio->codejercicio;

        if (null === self::findSequence($idempresa, $codserie, $codejercicio)) {
            $sequence = new SecuenciaDocumento();
            $sequence->idempresa = $idempresa;
            $sequence->codserie = $codserie;
            $sequence->codejercicio = $codejercicio;
            $sequence->tipodoc = self::TIPODOC;
            $sequence->patron = self::PATTERN;
            $sequence->longnumero = self::LONGNUMERO;
            $sequence->inicio = 1;
            $sequence->numero = 1;
            // FacturaCliente sequences must track gaps (core's own test()
            // warns 'use-holes-invoices-esp' otherwise) and this mirrors the
            // existing 2026 sequences already seeded for this series.
            $sequence->usarhuecos = true;
            if (false === $sequence->save()) {
                throw new \RuntimeException(
                    'could not create sequence for series ' . $codserie . ', exercise ' . $codejercicio
                    . ', company ' . $idempresa
                );
            }
        }

        return $codejercicio;
    }

    /** True when the exercise has at least one Subcuenta, i.e. a chart of accounts was imported. */
    public static function hasAccountingPlan(string $codejercicio): bool
    {
        return self::subcuentaCount($codejercicio) > 0;
    }

    /**
     * The sentence IssuerGuard adds as a blocker when an exercise has no
     * chart of accounts. Pure string formatting - no DB access - so it is
     * unit-tested on its own; only hasAccountingPlan() needs a database.
     */
    public static function missingAccountingPlanBlocker(string $codejercicio): string
    {
        return 'exercise ' . $codejercicio . ' has no chart of accounts (import the PGC first)';
    }

    /**
     * Replicates Ejercicio::createNew()'s own collision fallback - plain
     * year, then '00'+yy, then '0'+idempresa+yy, then the first free
     * 0001-9999 - without writing anything, so preview() can report the
     * code core WOULD assign on creation. $exists is injected so this stays
     * pure and unit-testable without a database: `ejercicios` is keyed by
     * codejercicio ALONE (not per company), which is exactly why company 2
     * ends up with '0026' once company 1 has already taken '2026'.
     */
    public static function candidateCode(string $isoDate, int $idempresa, callable $exists): string
    {
        $date = strtotime($isoDate);
        if (false === $date) {
            throw new \InvalidArgumentException('invalid date ' . $isoDate);
        }

        $year = date('Y', $date);
        $year2 = date('y', $date);

        foreach ([$year, '00' . $year2, sprintf('%04s', '0' . $idempresa . $year2)] as $candidate) {
            if (false === $exists($candidate)) {
                return $candidate;
            }
        }

        for ($num = 1; $num < 10000; $num++) {
            $code = sprintf('%04s', $num);
            if (false === $exists($code)) {
                return $code;
            }
        }

        // Unreachable in practice (10000 four-character codes exhausted),
        // but core's own createNew() has the same unguarded loop - mirrored
        // here, not improved on, since this function's job is prediction.
        throw new \RuntimeException('no free exercise code available');
    }

    private static function findSequence(int $idempresa, string $codserie, string $codejercicio): ?SecuenciaDocumento
    {
        $where = [
            Where::eq('idempresa', $idempresa),
            Where::eq('codserie', $codserie),
            Where::eq('codejercicio', $codejercicio),
            Where::eq('tipodoc', self::TIPODOC),
        ];
        foreach ((new SecuenciaDocumento())->all($where) as $row) {
            return $row;
        }

        return null;
    }

    private static function subcuentaCount(string $codejercicio): int
    {
        return Subcuenta::count([Where::eq('codejercicio', $codejercicio)]);
    }
}
