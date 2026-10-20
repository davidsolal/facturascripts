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
     * The settings key for a given ISO date, scoped to Profile::group()'s
     * settings group. Single source of truth: isIssued()/markIssued() both
     * call this instead of re-deriving 'issued-YYYY-MM' inline, so the two
     * can never drift apart.
     */
    public static function settingsKey(string $isoDate): string
    {
        return 'issued-' . substr($isoDate, 0, 7);
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

    public static function markIssued(int $idempresa, string $isoDate, string $codigo): void
    {
        // settingsSet(), not settings(): Tools::settings() is a getter whose
        // third argument is only a fallback for a missing key. Calling it here
        // registered an empty 'issued-YYYY-MM' key and persisted nothing, so
        // isIssued() stayed false forever and every run re-issued the invoice
        // (same trap documented in Profile::save()).
        Tools::settingsSet(Profile::group($idempresa), self::settingsKey($isoDate), $codigo);
        Tools::settingsSave();
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
