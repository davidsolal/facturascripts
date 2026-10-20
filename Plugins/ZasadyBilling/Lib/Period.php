<?php

namespace FacturaScripts\Plugins\ZasadyBilling\Lib;

use FacturaScripts\Core\Tools;

/**
 * Billing-period helpers: the human-readable period key used in logs/tests,
 * the settings-table key used to guard against issuing the same company's
 * invoice twice in one month, and the month-end/clamped-day date math that
 * invoiceDate()/dueDate() need.
 */
final class Period
{
    /**
     * Billing timezone. The operator, the companies and the client's payment
     * terms are all in Spain, but the container's clock runs UTC: on a run
     * started shortly after midnight CEST, "today" in UTC is still yesterday,
     * which made the monthly job miss its own billing day (or think it had not
     * arrived yet). Every "today" in the plugin is derived here.
     */
    public const TIMEZONE = 'Europe/Madrid';

    public static function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE));
    }

    /**
     * The settings key for a given ISO date, scoped to Profile::group()'s
     * settings group. Single source of truth: isIssued()/markIssued() both
     * call this instead of re-deriving 'issued-YYYY-MM' inline, so the two
     * can never drift apart.
     */
    public static function settingsKey(string $isoDate): string
    {
        return 'issued-' . substr($isoDate, 0, 7);
    }

    /** First day of the ISO date's month, e.g. '2026-09-01'. */
    public static function monthStart(string $isoDate): string
    {
        return substr($isoDate, 0, 7) . '-01';
    }

    /** Last day of the ISO date's month, e.g. '2026-09-30'. */
    public static function monthEnd(string $isoDate): string
    {
        return (new \DateTimeImmutable(self::monthStart($isoDate)))->format('Y-m-t');
    }

    /**
     * Human-readable identifier for logs/tests: the settings group plus the
     * settings key, e.g. 'zasady-2-issued-2026-09'.
     */
    public static function key(int $idempresa, string $isoDate): string
    {
        return Profile::group($idempresa) . '-' . self::settingsKey($isoDate);
    }

    public static function isIssued(int $idempresa, string $isoDate): bool
    {
        return Tools::settings(Profile::group($idempresa), self::settingsKey($isoDate), '') !== '';
    }

    /**
     * Persists the period marker and reports whether the settings table
     * accepted the write. Callers must honour the result: by the time this
     * runs the invoice is already saved and activated, so a silent failure
     * here is exactly what left four duplicate invoices behind during
     * acceptance - the invoice existed, the marker did not.
     */
    public static function markIssued(int $idempresa, string $isoDate, string $codigo): bool
    {
        // settingsSet(), not settings(): Tools::settings() is a getter whose
        // third argument is only a fallback for a missing key. Calling it here
        // registered an empty 'issued-YYYY-MM' key and persisted nothing, so
        // isIssued() stayed false forever and every run re-issued the invoice
        // (same trap documented in Profile::save()).
        Tools::settingsSet(Profile::group($idempresa), self::settingsKey($isoDate), $codigo);
        return Tools::settingsSave();
    }

    /**
     * Builds the invoice date for a billing day, clamping to the month's
     * actual length: day 19 exists every month, but a configured day of 31
     * would not in e.g. February or April.
     */
    public static function invoiceDate(int $year, int $month, int $day): string
    {
        $last = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        return sprintf('%04d-%02d-%02d', $year, $month, min($day, $last));
    }

    public static function dueDate(string $isoDate): string
    {
        $d = new \DateTimeImmutable($isoDate);
        return $d->format('Y-m-t');
    }
}
